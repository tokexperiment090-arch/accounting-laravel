# API PHP SDK (Slice A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A PHP client SDK (`Liberu\AccountingSdk`) for the core `/api/v1` accounting resources — bearer-token auth, CRUD + GL-report methods, typed exceptions — unit-tested with a Guzzle MockHandler.

**Architecture:** A `Client` wraps a Guzzle HTTP client configured with `base_uri = {baseUrl}/api/v1/` + an `Authorization: Bearer` header; its `request()` sends, maps HTTP error statuses to typed exceptions, and returns the decoded JSON array. Resource helpers (`CrudResource`, `GeneralLedger`) are thin objects that call `Client::request()` with the right verb/path. The Guzzle handler is injectable so tests drive it with `MockHandler` + history middleware and assert the outgoing request (verb/URI/query/body/bearer) without any live HTTP.

**Tech Stack:** PHP 8.5, `guzzlehttp/guzzle` (already installed), PHPUnit. The SDK lives in-repo at `sdk/php/src/` under namespace `Liberu\AccountingSdk\`, autoloaded via a PSR-4 entry in the app's `composer.json`; tests live in the app suite (`tests/Feature/Sdk/`) so they run under `artisan test`.

## Global Constraints

- `declare(strict_types=1);` on every new PHP file.
- SDK namespace is `Liberu\AccountingSdk\`, rooted at `sdk/php/src/` (relative to `src/`). Add ONE PSR-4 line to `composer.json` and run `docker compose run --rm composer dump-autoload` (the dedicated composer container, from repo root `/home/tom/code/accounting-laravel`) so the namespace resolves — the classes will not autoload until dump-autoload runs.
- The client targets the canonical `/api/v1/*` surface only. Base URI = `rtrim($baseUrl,'/').'/api/v1/'`; resource paths are relative (`invoices`, `general-ledger/trial-balance`) so Guzzle resolves them against the base.
- Auth is a **Sanctum bearer token** passed to the constructor and sent as `Authorization: Bearer {token}` — there is NO login/token HTTP endpoint; the token is generated out-of-band via the app's Filament personal-access-tokens page. Document this.
- Guzzle is configured with `http_errors => false`; the `Client` inspects the status itself and throws typed exceptions (`UnauthorizedException` 401, `ForbiddenException` 403, `ValidationException` 422 carrying `errors`, `RateLimitException` 429 carrying `Retry-After`, base `ApiException` for other ≥400). All extend `Liberu\AccountingSdk\Exception\ApiException`.
- Tests are pure unit tests (no DB, no Laravel boot): extend `PHPUnit\Framework\TestCase` directly, drive Guzzle with `GuzzleHttp\Handler\MockHandler` + `GuzzleHttp\Middleware::history`. **Never edit phpunit.xml.**
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: SDK scaffold + Client + typed exceptions

**Files:**
- Modify: `src/composer.json` (add the PSR-4 autoload entry)
- Create: `src/sdk/php/src/Client.php`
- Create: `src/sdk/php/src/Exception/ApiException.php`
- Create: `src/sdk/php/src/Exception/UnauthorizedException.php`
- Create: `src/sdk/php/src/Exception/ForbiddenException.php`
- Create: `src/sdk/php/src/Exception/ValidationException.php`
- Create: `src/sdk/php/src/Exception/RateLimitException.php`
- Test: `src/tests/Feature/Sdk/ClientTest.php`

**Interfaces:**
- Produces: `Liberu\AccountingSdk\Client` with `__construct(string $baseUrl, string $token, ?callable $handler = null)` and `request(string $method, string $path, array $options = []): array` (public — resources call it); resource accessors `invoices()/bills()/estimates()/chartOfAccounts()/journalEntries()` return `Liberu\AccountingSdk\Resources\CrudResource` and `generalLedger()` returns `Liberu\AccountingSdk\Resources\GeneralLedger` (both classes are built in Tasks 2–3; the accessor method bodies are written now and reference them). `Exception\ApiException` (base, `status(): int`), `UnauthorizedException`, `ForbiddenException`, `ValidationException` (`errors(): array`), `RateLimitException` (`retryAfter(): ?int`).

- [ ] **Step 1: Add the PSR-4 autoload entry + dump autoload**

In `src/composer.json`, add the SDK namespace to the `autoload.psr-4` map (the block currently has `App\\`, `Database\\Factories\\`, `Database\\Seeders\\`):

```json
        "psr-4": {
            "App\\": "app/",
            "Liberu\\AccountingSdk\\": "sdk/php/src/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        },
```

Then, from the repo root `/home/tom/code/accounting-laravel`:
```bash
docker compose run --rm composer dump-autoload
```
Expected: "Generated optimized autoload files" (the `Liberu\AccountingSdk\` root now resolves to `sdk/php/src/`).

- [ ] **Step 2: Write the failing test**

```php
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
```

- [ ] **Step 3: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=ClientTest`
Expected: FAIL (`Liberu\AccountingSdk\Client` not found).

- [ ] **Step 4: Create the exception classes**

```php
<?php // src/sdk/php/src/Exception/ApiException.php
declare(strict_types=1);
namespace Liberu\AccountingSdk\Exception;

class ApiException extends \RuntimeException
{
    public function __construct(string $message, int $status)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode();
    }
}
```

```php
<?php // src/sdk/php/src/Exception/UnauthorizedException.php
declare(strict_types=1);
namespace Liberu\AccountingSdk\Exception;

class UnauthorizedException extends ApiException {}
```

```php
<?php // src/sdk/php/src/Exception/ForbiddenException.php
declare(strict_types=1);
namespace Liberu\AccountingSdk\Exception;

class ForbiddenException extends ApiException {}
```

```php
<?php // src/sdk/php/src/Exception/ValidationException.php
declare(strict_types=1);
namespace Liberu\AccountingSdk\Exception;

class ValidationException extends ApiException
{
    /** @param array<string, mixed> $errors */
    public function __construct(string $message, int $status, private array $errors = [])
    {
        parent::__construct($message, $status);
    }

    /** @return array<string, mixed> */
    public function errors(): array
    {
        return $this->errors;
    }
}
```

```php
<?php // src/sdk/php/src/Exception/RateLimitException.php
declare(strict_types=1);
namespace Liberu\AccountingSdk\Exception;

class RateLimitException extends ApiException
{
    public function __construct(string $message, int $status, private ?int $retryAfter = null)
    {
        parent::__construct($message, $status);
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
```

- [ ] **Step 5: Create the Client**

```php
<?php // src/sdk/php/src/Client.php
declare(strict_types=1);
namespace Liberu\AccountingSdk;

use GuzzleHttp\Client as GuzzleClient;
use Liberu\AccountingSdk\Exception\ApiException;
use Liberu\AccountingSdk\Exception\ForbiddenException;
use Liberu\AccountingSdk\Exception\RateLimitException;
use Liberu\AccountingSdk\Exception\UnauthorizedException;
use Liberu\AccountingSdk\Exception\ValidationException;
use Liberu\AccountingSdk\Resources\CrudResource;
use Liberu\AccountingSdk\Resources\GeneralLedger;

class Client
{
    private GuzzleClient $http;

    public function __construct(string $baseUrl, string $token, ?callable $handler = null)
    {
        $config = [
            'base_uri' => rtrim($baseUrl, '/').'/api/v1/',
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        $this->http = new GuzzleClient($config);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $response = $this->http->request($method, ltrim($path, '/'), $options);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();
        $decoded = $body === '' ? [] : json_decode($body, true);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        if ($status >= 400) {
            throw $this->makeException($status, $decoded, $response->getHeaderLine('Retry-After'));
        }

        return $decoded;
    }

    /** @param array<mixed> $body */
    private function makeException(int $status, array $body, string $retryAfter): ApiException
    {
        $message = is_string($body['message'] ?? null) ? $body['message'] : 'API request failed';

        return match ($status) {
            401 => new UnauthorizedException($message, $status),
            403 => new ForbiddenException($message, $status),
            422 => new ValidationException($message, $status, is_array($body['errors'] ?? null) ? $body['errors'] : []),
            429 => new RateLimitException($message, $status, $retryAfter === '' ? null : (int) $retryAfter),
            default => new ApiException($message, $status),
        };
    }

    public function invoices(): CrudResource
    {
        return new CrudResource($this, 'invoices');
    }

    public function bills(): CrudResource
    {
        return new CrudResource($this, 'bills');
    }

    public function estimates(): CrudResource
    {
        return new CrudResource($this, 'estimates');
    }

    public function chartOfAccounts(): CrudResource
    {
        return new CrudResource($this, 'chart-of-accounts');
    }

    public function journalEntries(): CrudResource
    {
        return new CrudResource($this, 'journal-entries');
    }

    public function generalLedger(): GeneralLedger
    {
        return new GeneralLedger($this);
    }
}
```

Note: `CrudResource` and `GeneralLedger` do not exist yet (Tasks 2–3). The `ClientTest` above only exercises `request()` directly, so it passes now; do not call the accessors until their classes exist.

- [ ] **Step 6: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=ClientTest`
Expected: PASS (3 tests). If "class not found", re-run `docker compose run --rm composer dump-autoload`.

- [ ] **Step 7: Commit**

```bash
git -C src add composer.json sdk/php/src/Client.php sdk/php/src/Exception tests/Feature/Sdk/ClientTest.php
git -C src commit -m "feat(sdk): client + typed exceptions"
```

---

### Task 2: CrudResource + resource CRUD

**Files:**
- Create: `src/sdk/php/src/Resources/CrudResource.php`
- Test: `src/tests/Feature/Sdk/CrudResourceTest.php`

**Interfaces:**
- Consumes: `Liberu\AccountingSdk\Client::request(string $method, string $path, array $options = []): array` (Task 1).
- Produces: `Liberu\AccountingSdk\Resources\CrudResource` with `__construct(Client $client, string $path)` and `list(array $query = []): array` (GET `{path}`), `get(int|string $id): array` (GET `{path}/{id}`), `create(array $data): array` (POST `{path}`, JSON body), `update(int|string $id, array $data): array` (PUT `{path}/{id}`, JSON body), `delete(int|string $id): array` (DELETE `{path}/{id}`).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=CrudResourceTest`
Expected: FAIL (`Liberu\AccountingSdk\Resources\CrudResource` not found).

- [ ] **Step 3: Implement CrudResource**

```php
<?php // src/sdk/php/src/Resources/CrudResource.php
declare(strict_types=1);
namespace Liberu\AccountingSdk\Resources;

use Liberu\AccountingSdk\Client;

class CrudResource
{
    public function __construct(private Client $client, private string $path) {}

    /**
     * @param array<string, mixed> $query
     * @return array<mixed>
     */
    public function list(array $query = []): array
    {
        return $this->client->request('GET', $this->path, ['query' => $query]);
    }

    /** @return array<mixed> */
    public function get(int|string $id): array
    {
        return $this->client->request('GET', $this->path.'/'.$id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<mixed>
     */
    public function create(array $data): array
    {
        return $this->client->request('POST', $this->path, ['json' => $data]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<mixed>
     */
    public function update(int|string $id, array $data): array
    {
        return $this->client->request('PUT', $this->path.'/'.$id, ['json' => $data]);
    }

    /** @return array<mixed> */
    public function delete(int|string $id): array
    {
        return $this->client->request('DELETE', $this->path.'/'.$id);
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=CrudResourceTest`
Expected: PASS (2 tests). If the `list` URI assertion fails on query ordering, note Guzzle serialises the `query` array in insertion order — the test uses a single key so order is deterministic.

- [ ] **Step 5: Commit**

```bash
git -C src add sdk/php/src/Resources/CrudResource.php tests/Feature/Sdk/CrudResourceTest.php
git -C src commit -m "feat(sdk): CRUD resource client"
```

---

### Task 3: GeneralLedger resource + README

**Files:**
- Create: `src/sdk/php/src/Resources/GeneralLedger.php`
- Create: `src/sdk/php/README.md`
- Test: `src/tests/Feature/Sdk/GeneralLedgerTest.php`

**Interfaces:**
- Consumes: `Liberu\AccountingSdk\Client::request()` (Task 1); `Client::generalLedger()` returns this (Task 1 accessor).
- Produces: `Liberu\AccountingSdk\Resources\GeneralLedger` with `__construct(Client $client)`, `trialBalance(array $query = []): array` (GET `general-ledger/trial-balance`), `balances(array $query = []): array` (GET `general-ledger/balances`).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=GeneralLedgerTest`
Expected: FAIL (`Liberu\AccountingSdk\Resources\GeneralLedger` not found).

- [ ] **Step 3: Implement GeneralLedger**

```php
<?php // src/sdk/php/src/Resources/GeneralLedger.php
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
```

- [ ] **Step 4: Write the README**

```markdown
<!-- src/sdk/php/README.md -->
# Liberu Accounting — PHP SDK

A thin PHP client for the Liberu Accounting `/api/v1` API.

## Authentication

The API authenticates with a **Sanctum bearer token**. There is no login endpoint —
generate a personal access token in the app's **Filament panel** (Account → API Tokens),
scoped to the abilities you need (e.g. `invoices:read`, `invoices:write`), then pass it
to the client.

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
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=GeneralLedgerTest`
Then the whole SDK group: `docker compose exec -T php-fpm php artisan test --filter=Sdk`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add sdk/php/src/Resources/GeneralLedger.php sdk/php/README.md tests/Feature/Sdk/GeneralLedgerTest.php
git -C src commit -m "feat(sdk): general-ledger client + README"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`. (The SDK tests are pure-unit — no DB — but confirm the suite still green and the new namespace didn't disturb autoloading.)
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=Sdk`. (No DB dependency here, but run it — cheap.)
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`. If phpstan's configured paths do NOT include `sdk/`, the SDK source won't be analysed — that's acceptable for slice A; do NOT add `sdk/` to the phpstan paths in this slice (it would surface a wave of Guzzle-`mixed` idiom). If the SDK **test** files (under `tests/`) are analysed and only show the Eloquent/Guzzle-`mixed` idiom, regenerate the frozen baseline (`--generate-baseline phpstan-baseline.neon`) after verifying each is idiom.
- Pint the new files: `docker compose exec -T php-fpm ./vendor/bin/pint sdk/php/src tests/Feature/Sdk`.
- Adversarial review focus: the base URI always resolves to `…/api/v1/` and resource paths land on the right endpoints (no double slashes, no dropped `v1`); the bearer token is sent on every request and is NOT logged/leaked; error mapping is correct for each status incl. an empty/non-JSON body; `http_errors => false` so 4xx don't throw Guzzle's own exception before the typed mapping; the injected-handler seam doesn't bypass the auth header; that **no test edited phpunit.xml**.

## Self-Review

- **Spec coverage:** PHP client with bearer auth ✓ (T1 `Client`); `/api/v1` base + relative paths ✓ (T1); typed exceptions 401/403/422/429/other ✓ (T1); CRUD for invoices/bills/estimates/chart-of-accounts/journal-entries ✓ (T2 `CrudResource` + T1 accessors); GL trial-balance/balances ✓ (T3); README documenting the token flow ✓ (T3); MockHandler unit tests ✓ (all tasks). Deferred (TS client, OpenAPI schema-enrichment, bank connectors, DTOs, retry) intentionally absent.
- **Placeholders:** none — full code for every class + test; the composer edit shows the exact JSON.
- **Type consistency:** `Client::request(string,string,array): array` identical across T1/T2/T3; accessor return types (`CrudResource`, `GeneralLedger`) match the classes built in T2/T3; `CrudResource::__construct(Client,string)` and `GeneralLedger::__construct(Client)` match the `new` calls in T1's accessors; exception names + `errors()`/`retryAfter()`/`status()` consistent between T1 definitions and the T1 test assertions.
