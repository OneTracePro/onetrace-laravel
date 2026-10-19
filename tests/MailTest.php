<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Mail;
use OneTrace\Laravel\Mail\SendsThroughOneTrace;

final class MailTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        tap($app->make('config'), static function (Repository $config): void {
            $config->set('mail.default', 'failover');
            $config->set('mail.mailers.onetrace', ['transport' => 'onetrace']);
            $config->set('mail.mailers.native', ['transport' => 'native-test']);
            $config->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['onetrace', 'native']]);
            $config->set('mail.from', ['address' => 'shop@example.com', 'name' => 'Shop']);
        });
    }

    private ArrayTransport $native;

    protected function setUp(): void
    {
        parent::setUp();

        $this->native = new ArrayTransport();
        $this->app->make('mail.manager')->extend('native-test', fn (): ArrayTransport => $this->native);
    }

    public function testSendsEachRecipientACopyWithTheKindAndAttachments(): void
    {
        $this->transport->respond(202, ['id' => 'm-1'])->respond(202, ['id' => 'm-2']);

        Mail::to('anna@example.com')->cc('boss@example.com')->send(new OrderPlaced());

        $requests = array_values(array_filter($this->transport->requests, static fn ($request): bool => str_ends_with($request->getUrl(), '/api/v1/emails')));
        $first = json_decode((string) $requests[0]->getBody(), true);
        $second = json_decode((string) $requests[1]->getBody(), true);

        self::assertCount(2, $requests);
        self::assertSame('anna@example.com', $first['to']);
        self::assertSame('boss@example.com', $second['to']);
        self::assertSame('order_placed', $first['kind']);
        self::assertSame('Order 1001 received', $first['subject']);
        self::assertStringContainsString('<p>Thanks, Anna!</p>', $first['html']);
        self::assertSame('Shop', $first['from_name']);
        self::assertSame('orders@example.com', $first['reply_to']);
        self::assertSame([['name' => 'invoice-1001.pdf', 'type' => 'application/pdf', 'content' => base64_encode('%PDF-1.4')]], $first['attachments']);
        self::assertNotSame($requests[0]->getHeader('Idempotency-Key'), $requests[1]->getHeader('Idempotency-Key'));
        self::assertCount(0, $this->native->messages());
    }

    public function testFallsBackToTheNextMailerWhenTheEmailIsNotAccepted(): void
    {
        $this->transport->respond(402, ['message' => 'The monthly message quota is used up.', 'code' => 'quota_exceeded']);

        Mail::to('anna@example.com')->send(new OrderPlaced());

        $native = $this->native->messages();

        self::assertCount(1, $native);
        self::assertSame('Order 1001 received', $native[0]->getOriginalMessage()->getSubject());
    }
}

final class OrderPlaced extends Mailable
{
    use SendsThroughOneTrace;

    public function build(): self
    {
        return $this->subject('Order 1001 received')
            ->replyTo('orders@example.com')
            ->html('<p>Thanks, Anna!</p>')
            ->attach(Attachment::fromData(static fn (): string => '%PDF-1.4', 'invoice-1001.pdf')->withMime('application/pdf'))
            ->oneTraceKind('order_placed');
    }
}
