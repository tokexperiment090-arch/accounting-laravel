<?php

declare(strict_types=1);

namespace Tests\Feature\Sdk;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Liberu\AccountingSdk\Client;
use PHPUnit\Framework\TestCase;

class CrudResourceTest extends TestCase
{
    /** @param list<Response> $responses @param array<int,array<string,mixed>> $history */
    private function client(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new Client('https://acct.test', 'T', $stack);
    }

    public function test_crud_builds_the_right_requests(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [], (string) json_encode(['data' => []])),      // list
            new Response(200, [], (string) json_encode(['id' => 7])),         // get
            new Response(201, [], (string) json_encode(['id' => 8])),         // create
            new Response(200, [], (string) json_encode(['id' => 8])),         // update
            new Response(200, [], (string) json_encode(['deleted' => true])), // delete
        ], $history);

        $invoices = $client->invoices();
        $invoices->list(['status' => 'pending']);
        $invoices->get(7);
        $created = $invoices->create(['total_amount' => 100]);
        $invoices->update(8, ['payment_status' => 'paid']);
        $deleted = $invoices->delete(8);

        $this->assertSame(['id' => 8], $created);
        $this->assertSame(['deleted' => true], $deleted);

        [$list, $get, $create, $update, $del] = array_map(fn ($h) => $h['request'], $history);

        $this->assertSame('GET', $list->getMethod());
        $this->assertSame('https://acct.test/api/v1/invoices?status=pending', (string) $list->getUri());

        $this->assertSame('GET', $get->getMethod());
        $this->assertSame('https://acct.test/api/v1/invoices/7', (string) $get->getUri());

        $this->assertSame('POST', $create->getMethod());
        $this->assertSame('https://acct.test/api/v1/invoices', (string) $create->getUri());
        $this->assertSame('{"total_amount":100}', (string) $create->getBody());

        $this->assertSame('PUT', $update->getMethod());
        $this->assertSame('https://acct.test/api/v1/invoices/8', (string) $update->getUri());
        $this->assertSame('{"payment_status":"paid"}', (string) $update->getBody());

        $this->assertSame('DELETE', $del->getMethod());
        $this->assertSame('https://acct.test/api/v1/invoices/8', (string) $del->getUri());
    }

    public function test_chart_of_accounts_uses_the_hyphenated_path(): void
    {
        $history = [];
        $client = $this->client([new Response(200, [], (string) json_encode([]))], $history);

        $client->chartOfAccounts()->list();

        $this->assertSame('https://acct.test/api/v1/chart-of-accounts', (string) $history[0]['request']->getUri());
    }
}
