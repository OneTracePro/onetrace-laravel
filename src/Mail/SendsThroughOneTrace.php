<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Mail;

use Symfony\Component\Mime\Email;

/**
 * For mailables sent through the "onetrace" mail transport: the kind of the email in OneTrace.pro reports and the
 * customer profile — order_placed, password_reset … Access-link kinds (password_reset, account_new,
 * email_verification, login_link, login_code) go without click tracking and keep only the subject after sending.
 *
 * @mixin \Illuminate\Mail\Mailable
 */
trait SendsThroughOneTrace
{
    public function oneTraceKind(string $kind): static
    {
        return $this->withSymfonyMessage(static function (Email $message) use ($kind): void {
            $message->getHeaders()->remove(OneTraceTransport::KIND_HEADER);
            $message->getHeaders()->addTextHeader(OneTraceTransport::KIND_HEADER, $kind);
        });
    }
}
