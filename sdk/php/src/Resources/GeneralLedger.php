<?php
declare(strict_types=1);

namespace Liberu\AccountingSdk\Resources;

use Liberu\AccountingSdk\Client;

class GeneralLedger
{
    public function __construct(private Client $client) {}

    /**
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function trialBalance(array $query = []): array
    {
        return $this->client->request('GET', 'general-ledger/trial-balance', ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function balances(array $query = []): array
    {
        return $this->client->request('GET', 'general-ledger/balances', ['query' => $query]);
    }
}
