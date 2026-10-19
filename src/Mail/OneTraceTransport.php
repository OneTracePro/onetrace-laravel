<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Mail;

use OneTrace\Client;
use OneTrace\Exception\OneTraceException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Laravel mail transport "onetrace": every email of the application goes out through the project's email integration
 * of OneTrace.pro (POST /emails) as is — with delivery statuses and history in the customer profile. Each recipient
 * (To, Cc, Bcc) gets their own copy. A failure throws TransportException, so a failover mailer sends the email
 * natively (see README).
 *
 * The kind of the email (order_placed, password_reset …) comes from the X-OneTrace-Kind header (see SendsThroughOneTrace),
 * otherwise from the mailer's "kind" option.
 */
final class OneTraceTransport extends AbstractTransport
{
    public const KIND_HEADER = 'X-OneTrace-Kind';

    /**
     * @param \Closure(): Client $client resolved on send: the transport outlives requests under Octane
     */
    public function __construct(private readonly \Closure $client, private readonly string $kind = 'other')
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (!$original instanceof Message) {
            throw new TransportException('OneTrace.pro sends only MIME messages, not raw ones.');
        }

        $email = MessageConverter::toEmail($original);
        $headers = $email->getHeaders();
        $kind = $headers->has(self::KIND_HEADER) ? strtolower(trim((string) $headers->get(self::KIND_HEADER)?->getBodyAsString())) : $this->kind;
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $from = $email->getFrom()[0] ?? null;
        $payload = array_filter([
            'kind' => preg_match('/^[a-z0-9_]{1,40}$/', $kind) === 1 ? $kind : 'other',
            'subject' => (string) $email->getSubject(),
            'html' => \is_string($html) ? $html : '<pre>' . htmlspecialchars(\is_string($text) ? $text : '', ENT_QUOTES) . '</pre>',
            'text' => \is_string($text) ? $text : null,
            'reply_to' => ($email->getReplyTo()[0] ?? null)?->getAddress(),
            'from_name' => $from !== null && $from->getName() !== '' ? $from->getName() : null,
            'attachments' => $this->attachments($email),
        ], static fn ($value): bool => $value !== null && $value !== []);

        $recipients = array_map(static fn (Address $address): string => $address->getAddress(), [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]);

        foreach (array_unique($recipients) as $recipient) {
            try {
                $result = ($this->client)()->emails()->send(['to' => $recipient] + $payload, hash('sha256', $message->getMessageId() . '|' . $recipient));
            } catch (OneTraceException $e) {
                throw new TransportException(sprintf('OneTrace.pro did not accept the email to %s: %s', $recipient, $e->getMessage()), 0, $e);
            }

            $message->appendDebug(sprintf("OneTrace message %s to %s\n", (string) ($result['id'] ?? '?'), $recipient));
        }
    }

    /**
     * @return list<array{name: string, type: string, content: string}>
     */
    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $part) {
            $name = $part->getFilename() ?? 'attachment';
            $attachments[] = ['name' => $name, 'type' => $part->getMediaType() . '/' . $part->getMediaSubtype(), 'content' => base64_encode($part->getBody())];
        }

        return $attachments;
    }

    public function __toString(): string
    {
        return 'onetrace';
    }
}
