<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Tells the admin a new complaint has arrived. Replying answers the sender. */
class ContactSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContactSubmission $submission)
    {
        $this->replyTo($submission->email, $submission->name);
    }

    public function build(): self
    {
        $s = $this->submission;

        return $this->subject('FridgeToFork complaint #' . $s->id . ' (' . $s->category . ')')
            ->html(
                '<h2>New complaint on FridgeToFork</h2>' .
                '<p><strong>From:</strong> ' . e($s->name) . ' &lt;' . e($s->email) . '&gt;</p>' .
                '<p><strong>Type:</strong> ' . e(ucfirst($s->category)) . '</p>' .
                '<p><strong>Message:</strong><br>' . nl2br(e($s->message)) . '</p>' .
                '<p>Reply and mark it resolved in the Admin Dashboard: ' .
                '<a href="' . e(rtrim((string) config('app.url'), '/') . '/admin') . '">Contacts tab</a>.</p>'
            );
    }
}
