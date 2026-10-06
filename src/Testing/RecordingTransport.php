<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Testing;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\Http\Transport;

/**
 * A transport that sends nothing: records requests and answers with queued responses (an empty JSON object by default).
 */
class RecordingTransport implements Transport
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response> */
    private array $responses = [];

    /**
     * @param array<string, mixed>|list<mixed> $json
     */
    public function respond(int $status = 200, array $json = []): static
    {
        $this->responses[] = new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($json === [] ? new \stdClass() : $json));

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new Response(200, ['Content-Type' => 'application/json'], '{}');
    }
}
