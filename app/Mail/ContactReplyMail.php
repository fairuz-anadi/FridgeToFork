<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Sends the admin's reply back to the person who complained. */
class ContactReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContactSubmission $submission)
    {
    }

    public function build(): self
    {
        $s = $this->submission;

        return $this->subject('Re: your FridgeToFork message #' . $s->id)
            ->html(
                '<p>Hi ' . e($s->name) . ',</p>' .
                '<p>' . nl2br(e($s->admin_reply)) . '</p>' .
                '<hr><p style="color:#666"><strong>Your message:</strong><br>' . nl2br(e($s->message)) . '</p>' .
                '<p style="color:#666">— FridgeToFork admin</p>'
            );
    }
}
