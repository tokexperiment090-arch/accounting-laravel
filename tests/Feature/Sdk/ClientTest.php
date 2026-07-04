<?php
declare(strict_types=1);

namespace Tests\Feature\Sdk;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Liberu\AccountingSdk\Client;
use Liberu\AccountingSdk\Exception\ForbiddenException;
use Liberu\AccountingSdk\Exception\RateLimitException;
use Liberu\AccountingSdk\Exception\UnauthorizedException;
use Liberu\AccountingSdk\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    /** @param list<Response> $responses @param array<int,array<string,mixed>> $history */
    private function client(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client('https://acct.test/', 'TESTTOKEN', $stack);
    }

    public function test_request_sends_bearer_token_to_the_v1_base_and_decodes_json(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], (string) json_encode(['ok' => true]))], $history);

        $result = $client->request('GET', 'invoices');

        $this->assertSame(['ok' => true], $result);
        $request = $history[0]['request'];
        $this->assertSame('https://acct.test/api/v1/invoices', (string) $request->getUri());
        $this->assertSame('Bearer TESTTOKEN', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function test_maps_error_statuses_to_typed_exceptions(): void
    {
        $history = [];

        $u = $this->client([new Response(401, [], (string) json_encode(['message' => 'no']))], $history);
        $this->expectException(UnauthorizedException::class);
        $u->request('GET', 'invoices');
    }

    public function test_forbidden_validation_and_rate_limit(): void
    {
        $history = [];
        $forbidden = $this->client([new Response(403, [], (string) json_encode(['message' => 'nope']))], $history);
        try {
            $forbidden->request('GET', 'invoices');
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException $e) {
            $this->assertSame(403, $e->status());
        }

        $validation = $this->client([new Response(422, [], (string) json_encode(['message' => 'bad', 'errors' => ['total_amount' => ['required']]]))], $history);
        try {
            $validation->request('POST', 'invoices', ['json' => []]);
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['total_amount' => ['required']], $e->errors());
        }

        $rate = $this->client([new Response(429, ['Retry-After' => '30'], (string) json_encode(['message' => 'slow down']))], $history);
        try {
            $rate->request('GET', 'invoices');
            $this->fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->retryAfter());
        }
    }
}
