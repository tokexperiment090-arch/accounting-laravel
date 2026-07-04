# Liberu Accounting — PHP SDK

A thin PHP client for the Liberu Accounting `/api/v1` API.

## Authentication

The API authenticates with a **Sanctum bearer token**. There is no login endpoint —
generate a personal access token in the app's **Filament panel** (Account → Personal
Access Tokens), then pass it to the client.

Tokens created through that page are currently **full-access** (`*`) — the token-creation
UI does not yet expose an ability picker. The `/api/v1/*` routes are individually gated by
`resource:read` / `resource:write` abilities, so a future scoped-token flow will work
without SDK changes; today a `*` token satisfies every gate.

## Usage

```php
use Liberu\AccountingSdk\Client;

$client = new Client('https://your-accounting-host', $token);

// CRUD resources: invoices, bills, estimates, chartOfAccounts, journalEntries
$invoices = $client->invoices()->list(['status' => 'pending']);
$invoice  = $client->invoices()->create(['customer_id' => 1, 'total_amount' => 250]);
$one      = $client->invoices()->get($invoice['id']);
$client->invoices()->update($invoice['id'], ['payment_status' => 'paid']);
$client->invoices()->delete($invoice['id']);

// General-ledger reports
$trial = $client->generalLedger()->trialBalance();
$bals  = $client->generalLedger()->balances();
```

Note: `journalEntries()` has no `update()` on the server (the API exposes index/store/show/destroy only);
calling `update()` will raise an `ApiException`.

## Errors

Non-2xx responses raise typed exceptions (all extend `Liberu\AccountingSdk\Exception\ApiException`):

| Status | Exception | Extra |
|--------|-----------|-------|
| 401 | `UnauthorizedException` | |
| 403 | `ForbiddenException` | |
| 422 | `ValidationException` | `->errors()` |
| 429 | `RateLimitException` | `->retryAfter()` (seconds) |
| other ≥400 | `ApiException` | `->status()` |

Only HTTP-status errors are mapped. Transport-level failures (DNS, timeout, connection
reset) still surface as the underlying `GuzzleHttp\Exception\*` — catch those separately if
you need to retry. Automatic retry/backoff is deferred to a later slice.
