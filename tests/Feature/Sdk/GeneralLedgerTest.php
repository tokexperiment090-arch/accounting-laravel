<?php

declare(strict_types=1);

namespace Tests\Feature\Sdk;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Liberu\AccountingSdk\Client;
use PHPUnit\Framework\TestCase;

class GeneralLedgerTest extends TestCase
{
    public function test_general_ledger_report_endpoints(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], (string) json_encode(['rows' => []])),
            new Response(200, [], (string) json_encode(['balances' => []])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client('https://acct.test', 'T', $stack);

        $tb = $client->generalLedger()->trialBalance(['as_of' => '2026-06-30']);
        $client->generalLedger()->balances();

        $this->assertSame(['rows' => []], $tb);
        $this->assertSame('https://acct.test/api/v1/general-ledger/trial-balance?as_of=2026-06-30', (string) $history[0]['request']->getUri());
        $this->assertSame('https://acct.test/api/v1/general-ledger/balances', (string) $history[1]['request']->getUri());
    }
}
