<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through Brevo's HTTPS API. Render's free plan blocks outgoing
 * SMTP ports, so plain SMTP (e.g. Gmail) cannot leave the server.
 */
class BrevoTransport extends AbstractTransport
{
    public function __construct(private string $key)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $contact = fn (Address $address) => array_filter(['email' => $address->getAddress(), 'name' => $address->getName()]);
        $from = $email->getFrom()[0];

        Http::withHeaders(['api-key' => $this->key])
            ->acceptJson()
            ->timeout(15)
            ->post('https://api.brevo.com/v3/smtp/email', array_filter([
                'sender' => $contact($from),
                'to' => array_map($contact, $email->getTo()),
                'replyTo' => $email->getReplyTo() ? $contact($email->getReplyTo()[0]) : null,
                'subject' => $email->getSubject(),
                'htmlContent' => $email->getHtmlBody(),
                'textContent' => $email->getTextBody(),
            ]))
            ->throw();
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
