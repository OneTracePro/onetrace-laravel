<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OneTrace\Laravel\StoreMcp\StoreMcp;

/**
 * POST {onetrace.store_mcp.path}: the store MCP server for the OneTrace AI chat bot (no session, no CSRF — the
 * platform authenticates with the key).
 */
final class StoreMcpController
{
    public function __invoke(Request $request, StoreMcp $mcp): Response
    {
        $response = $mcp->server()->handle($request->getMethod(), ['Authorization' => (string) $request->headers->get('Authorization', '')], $request->getContent());

        return new Response($response['body'], $response['status'], $response['headers']);
    }
}
