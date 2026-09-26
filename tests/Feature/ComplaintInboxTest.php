<?php

namespace Tests\Feature;

use App\Mail\ContactReplyMail;
use App\Mail\ContactSubmissionMail;
use App\Mail\Transport\BrevoTransport;
use App\Models\ContactSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Contact Admin: complaints reach the admin (inbox + email), the admin
 * replies and resolves them, and the sender sees the reply.
 */
class ComplaintInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.complaints_to' => 'owner@example.com']);
    }

    private function cook(): User
    {
        return User::factory()->create(['name' => 'Rina', 'username' => 'rina', 'email' => 'rina@example.com']);
    }

    private function admin(): User
    {
        return User::factory()->create(['username' => 'boss', 'is_admin' => true]);
    }

    public function test_a_guest_cannot_send_a_complaint(): void
    {
        $this->postJson('/api/contact', ['category' => 'bug', 'message' => 'The timer never rings.'])
            ->assertUnauthorized();
    }

    public function test_a_complaint_is_saved_and_emailed_to_the_admin(): void
    {
        Mail::fake();
        $cook = $this->cook();
        Sanctum::actingAs($cook);

        $this->postJson('/api/contact', ['category' => 'bug', 'message' => 'The timer never rings.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('contact_submissions', [
            'user_id' => $cook->id,
            'email' => 'rina@example.com',
            'category' => 'bug',
            'status' => 'open',
        ]);
        Mail::assertSent(ContactSubmissionMail::class, fn ($mail) => $mail->hasTo('owner@example.com')
            && $mail->hasReplyTo('rina@example.com'));
    }

    public function test_the_complaint_is_kept_when_email_fails(): void
    {
        config(['mail.default' => 'brevo', 'mail.mailers.brevo.key' => 'bad-key']);
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Key not found'], 401)]);
        Sanctum::actingAs($this->cook());

        $this->postJson('/api/contact', ['category' => 'other', 'message' => 'Hello, is anyone there?'])
            ->assertCreated();

        $this->assertSame(1, ContactSubmission::count());
    }

    public function test_message_length_limits(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->cook());
        $send = fn (int $length) => $this->postJson('/api/contact', ['category' => 'other', 'message' => str_repeat('a', $length)]);

        $send(10)->assertCreated();
        $send(5000)->assertCreated();
        $send(9)->assertStatus(422)->assertJsonValidationErrors('message');
        $send(5001)->assertStatus(422)->assertJsonValidationErrors('message');
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        Sanctum::actingAs($this->cook());

        $this->postJson('/api/contact', ['category' => 'spam', 'message' => 'Buy my stuff please.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_a_cook_sees_only_their_own_messages(): void
    {
        $cook = $this->cook();
        ContactSubmission::create(['user_id' => $cook->id, 'name' => 'Rina', 'email' => 'rina@example.com', 'category' => 'bug', 'message' => 'Mine, not yours.']);
        ContactSubmission::create(['name' => 'Someone', 'email' => 'x@example.com', 'category' => 'bug', 'message' => 'Somebody else.']);
        Sanctum::actingAs($cook);

        $this->getJson('/api/contact')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Mine, not yours.');
    }

    public function test_only_admins_can_open_the_inbox(): void
    {
        Sanctum::actingAs($this->cook());

        $this->getJson('/api/admin/contacts')->assertForbidden();
    }

    public function test_the_admin_replies_and_resolves_and_the_cook_is_emailed(): void
    {
        Mail::fake();
        $cook = $this->cook();
        $complaint = ContactSubmission::create(['user_id' => $cook->id, 'name' => 'Rina', 'email' => 'rina@example.com', 'category' => 'recipe', 'message' => 'The biryani needs salt.']);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/contacts?status=open')
            ->assertOk()
            ->assertJsonPath('meta.open', 1)
            ->assertJsonPath('data.0.id', $complaint->id);

        $this->patchJson("/api/admin/contacts/{$complaint->id}", ['admin_reply' => 'Fixed, thanks!', 'status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $complaint->refresh();
        $this->assertSame('Fixed, thanks!', $complaint->admin_reply);
        $this->assertNotNull($complaint->resolved_at);
        Mail::assertSent(ContactReplyMail::class, fn ($mail) => $mail->hasTo('rina@example.com'));

        // Reopening without a new reply sends no second email.
        $this->patchJson("/api/admin/contacts/{$complaint->id}", ['status' => 'open'])->assertOk();
        $this->assertNull($complaint->fresh()->resolved_at);
        Mail::assertSent(ContactReplyMail::class, 1);
    }

    public function test_archived_complaints_are_kept_and_can_be_restored_or_deleted(): void
    {
        $complaint = ContactSubmission::create(['name' => 'Rina', 'email' => 'rina@example.com', 'category' => 'bug', 'message' => 'Old report.']);
        Sanctum::actingAs($this->admin());

        $this->patchJson("/api/admin/contacts/{$complaint->id}", ['status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->getJson('/api/admin/contacts?status=open')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/contacts?status=archived')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.archived', 1);

        $this->patchJson("/api/admin/contacts/{$complaint->id}", ['status' => 'open'])
            ->assertJsonPath('data.status', 'open');
        $this->deleteJson("/api/admin/contacts/{$complaint->id}")->assertOk();
        $this->assertDatabaseMissing('contact_submissions', ['id' => $complaint->id]);
    }

    public function test_the_brevo_mailer_posts_to_the_brevo_api(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'abc'], 201)]);

        $transport = new BrevoTransport('test-key');
        $transport->send((new Email())
            ->from('noreply@example.com')
            ->to('owner@example.com')
            ->replyTo('rina@example.com')
            ->subject('Complaint #1')
            ->html('<p>Hi</p>'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->hasHeader('api-key', 'test-key')
            && $request['to'][0]['email'] === 'owner@example.com'
            && $request['replyTo']['email'] === 'rina@example.com'
            && $request['subject'] === 'Complaint #1');
    }
}
