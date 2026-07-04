# Tenant Provisioning (Slice A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provision a team with a standard 18-account double-entry chart of accounts (via a service, a command, and a Filament button), filling the gap that no chart-of-accounts seeder exists today.

**Architecture:** A `TenantProvisioningService` holds a canonical chart (account number + name + type) and `provisionChartOfAccounts(Team): int` creates those `Account` rows for the team, idempotently (skips if the team already has any account). A `tenants:provision-chart {team}` command and a "Seed standard chart" Filament header action are thin wrappers over the service (the command for existing teams / ops, the action for the current tenant in the app panel).

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- `Account`'s `team_id` and `user_id` are **NOT fillable** (its `creating` hook stamps them from `auth()` only). Provisioning runs with no reliable `auth()` (command / ops context), so set both explicitly via `forceFill([... 'team_id' => $team->getKey(), 'user_id' => $team->user_id ...])->save()` — never rely on the hook or a mass-assign array for these (same trap as the revenue-recognition JE posting). `account_number` DB-defaults nothing and is **required**; `normal_balance` is auto-derived by the `creating` hook (asset/expense → debit, else credit) when not set — do NOT set it, let the hook derive it.
- `account_number` is unique per `(team_id, account_number)`. The 18 canonical numbers are distinct, so one full chart per team is valid.
- Idempotent: `provisionChartOfAccounts` returns 0 and creates nothing if the team already has ANY account. The batch is wrapped in one `DB::transaction` (all-or-nothing).
- Tests: sqlite `:memory:` ENFORCES FKs; **never disable FK enforcement / never edit phpunit.xml / never weaken a guard**. No `TeamFactory` — `Team::forceCreate(['user_id'=>$u->id,'name'=>'X','personal_team'=>false])`. `accounts.team_id` + `accounts.user_id` are real FKs; `accounts.currency_id` is nullable with no FK (leave it null).
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: TenantProvisioningService + canonical chart

**Files:**
- Create: `src/app/Services/TenantProvisioningService.php`
- Test: `src/tests/Feature/Provisioning/TenantProvisioningServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\Account` (fillable incl. `account_number, account_name, account_type`; `creating` hook derives `normal_balance`; `team_id`/`user_id` non-fillable), `App\Models\Team`.
- Produces: `TenantProvisioningService::provisionChartOfAccounts(Team $team): int` — creates the 18 canonical `Account` rows for `$team` (stamping `team_id` = team key and `user_id` = team owner via `forceFill`), returns the count; returns 0 (creates nothing) if the team already has any account. The chart is the private `const CHART` of `[number, name, type]` triples below.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function team(): Team
    {
        $user = User::factory()->create();

        return Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
    }

    public function test_provisions_the_standard_chart_with_correct_types_and_normal_balance(): void
    {
        $team = $this->team();

        $count = app(TenantProvisioningService::class)->provisionChartOfAccounts($team);

        $this->assertSame(18, $count);
        $accounts = Account::where('team_id', $team->id)->get();
        $this->assertCount(18, $accounts);

        // key accounts the app's GL features depend on, with the right type + derived normal_balance
        $ar = $accounts->firstWhere('account_number', 1100);
        $this->assertSame('Accounts Receivable', $ar->account_name);
        $this->assertSame('asset', $ar->account_type);
        $this->assertSame('debit', $ar->normal_balance);

        $deferred = $accounts->firstWhere('account_number', 2400);
        $this->assertSame('Deferred Revenue', $deferred->account_name);
        $this->assertSame('liability', $deferred->account_type);
        $this->assertSame('credit', $deferred->normal_balance);

        $sales = $accounts->firstWhere('account_number', 4000);
        $this->assertSame('revenue', $sales->account_type);
        $this->assertSame('credit', $sales->normal_balance);

        // every row stamped with the team + owner (not a default team, not null)
        $this->assertTrue($accounts->every(fn (Account $a): bool => (int) $a->team_id === $team->id));
        $this->assertTrue($accounts->every(fn (Account $a): bool => (int) $a->user_id === $team->user_id));
    }

    public function test_is_idempotent_when_the_team_already_has_accounts(): void
    {
        $team = $this->team();
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);

        $again = app(TenantProvisioningService::class)->provisionChartOfAccounts($team->fresh());

        $this->assertSame(0, $again);
        $this->assertSame(18, Account::where('team_id', $team->id)->count());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=TenantProvisioningServiceTest`
Expected: FAIL (`App\Services\TenantProvisioningService` not found).

- [ ] **Step 3: Implement the service**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class TenantProvisioningService
{
    /** @var array<int, array{0:int,1:string,2:string}> [account_number, account_name, account_type] */
    private const CHART = [
        [1000, 'Cash', 'asset'],
        [1100, 'Accounts Receivable', 'asset'],
        [1200, 'Inventory', 'asset'],
        [1500, 'Fixed Assets', 'asset'],
        [2000, 'Accounts Payable', 'liability'],
        [2200, 'Sales Tax Payable', 'liability'],
        [2400, 'Deferred Revenue', 'liability'],
        [2600, 'Loans Payable', 'liability'],
        [3000, 'Owner Equity', 'equity'],
        [3200, 'Retained Earnings', 'equity'],
        [4000, 'Sales Revenue', 'revenue'],
        [4100, 'Other Income', 'revenue'],
        [5000, 'Cost of Goods Sold', 'expense'],
        [5100, 'Operating Expenses', 'expense'],
        [5200, 'Payroll Expense', 'expense'],
        [5300, 'Rent Expense', 'expense'],
        [5400, 'Utilities Expense', 'expense'],
        [5500, 'Depreciation Expense', 'expense'],
    ];

    public function provisionChartOfAccounts(Team $team): int
    {
        if (Account::where('team_id', $team->getKey())->exists()) {
            return 0;
        }

        return DB::transaction(function () use ($team): int {
            $count = 0;
            foreach (self::CHART as [$number, $name, $type]) {
                // team_id + user_id are NOT fillable and there is no auth() in the command/ops
                // context; set them explicitly so every account is team-scoped and owned.
                // normal_balance is intentionally left unset — Account's creating hook derives it.
                (new Account)->forceFill([
                    'account_number' => $number,
                    'account_name' => $name,
                    'account_type' => $type,
                    'team_id' => $team->getKey(),
                    'user_id' => $team->user_id,
                ])->save();
                $count++;
            }

            return $count;
        });
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=TenantProvisioningServiceTest`
Expected: PASS (2 tests). If `normal_balance` comes back null, confirm you did NOT set it in `forceFill` (the hook only derives it when unset). If an FK error on `user_id`, confirm `$team->user_id` resolves (the test's `Team::forceCreate` sets it).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/TenantProvisioningService.php tests/Feature/Provisioning/TenantProvisioningServiceTest.php
git -C src commit -m "feat(provisioning): standard chart of accounts"
```

---

### Task 2: tenants:provision-chart command

**Files:**
- Create: `src/app/Console/Commands/ProvisionTenantChart.php`
- Test: `src/tests/Feature/Provisioning/ProvisionTenantChartCommandTest.php`

**Interfaces:**
- Consumes: `TenantProvisioningService::provisionChartOfAccounts(Team): int` (Task 1); `App\Models\Team`.
- Produces: `tenants:provision-chart {team}` — resolves the team by id (the `{team}` argument), calls the service, prints the count (or a "skipped" message if 0); exits non-zero if the team id is unknown.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionTenantChartCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_provisions_the_team_by_id(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);

        $this->artisan('tenants:provision-chart', ['team' => $team->id])->assertSuccessful();

        $this->assertSame(18, Account::where('team_id', $team->id)->count());
    }

    public function test_command_fails_cleanly_for_an_unknown_team(): void
    {
        $this->artisan('tenants:provision-chart', ['team' => 999999])->assertFailed();

        $this->assertSame(0, Account::count());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=ProvisionTenantChartCommandTest`
Expected: FAIL (command `tenants:provision-chart` not defined).

- [ ] **Step 3: Create the command**

```php
<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\TenantProvisioningService;
use Illuminate\Console\Command;

class ProvisionTenantChart extends Command
{
    #[\Override]
    protected $signature = 'tenants:provision-chart {team : Team ID}';

    #[\Override]
    protected $description = 'Provision a standard chart of accounts for a team';

    public function handle(TenantProvisioningService $service): int
    {
        $team = Team::find($this->argument('team'));
        if (! $team instanceof Team) {
            $this->error("Team {$this->argument('team')} not found.");

            return self::FAILURE;
        }

        $count = $service->provisionChartOfAccounts($team);
        $this->info($count > 0
            ? "Provisioned {$count} accounts for team {$team->id}."
            : "Team {$team->id} already has accounts; skipped.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=ProvisionTenantChartCommandTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Console/Commands/ProvisionTenantChart.php tests/Feature/Provisioning/ProvisionTenantChartCommandTest.php
git -C src commit -m "feat(provisioning): tenants:provision-chart command"
```

---

### Task 3: Filament "Seed standard chart" header action

**Files:**
- Modify: `src/app/Filament/App/Resources/ChartOfAccounts/Pages/ListChartOfAccounts.php`
- Test: `src/tests/Feature/Provisioning/ProvisionChartActionTest.php`

**Interfaces:**
- Consumes: `TenantProvisioningService::provisionChartOfAccounts(Team): int` (Task 1); the Filament current tenant (`Filament\Facades\Filament::getTenant()`).
- Produces: a "Seed standard chart" header action (name `provisionChart`) on the ChartOfAccounts list page that provisions the current tenant and shows a notification with the count (or an "already exists" message).

- [ ] **Step 1: Write the failing test**

Mirror `src/tests/Feature/Approval/ApprovalRuleResourceTest.php` for the exact tenant/panel setup (it uses `Filament::setTenant($team)` + `Livewire::test(...)->callAction(...)` and makes the user a member of the team). The test:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use App\Filament\App\Resources\ChartOfAccounts\Pages\ListChartOfAccounts;
use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProvisionChartActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_action_provisions_the_current_tenant(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->teams()->attach($team); // membership so the panel accepts the tenant
        $user->forceFill(['current_team_id' => $team->id])->save();

        Filament::setTenant($team);
        $this->actingAs($user);

        Livewire::test(ListChartOfAccounts::class)
            ->callAction('provisionChart');

        $this->assertSame(18, Account::where('team_id', $team->id)->count());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=ProvisionChartActionTest`
Expected: FAIL (action `provisionChart` not registered).

If the test errors on tenant/panel setup rather than the missing action (e.g. the panel rejects the tenant), open `tests/Feature/Approval/ApprovalRuleResourceTest.php` and copy its exact `setUp`/membership/`Filament::setTenant` sequence — that file is a known-working Filament app-panel page test in this repo. Do NOT add Livewire retries or timeouts; if the panel setup fights back after mirroring that file, report it rather than flailing.

- [ ] **Step 3: Add the header action**

Read the current `src/app/Filament/App/Resources/ChartOfAccounts/Pages/ListChartOfAccounts.php` — it already has `getHeaderActions()` returning `[CreateAction::make(), $this->exportAction(), $this->importAction()]` and private `Action`-returning helper methods (`exportAction()`, `importAction()`) using `Filament\Actions\Action` + `Filament\Notifications\Notification`. Mirror that pattern exactly.

Add these imports (if not already present):
```php
use App\Services\TenantProvisioningService;
use Filament\Facades\Filament;
```

Register the new action in `getHeaderActions()` (append it):
```php
        return [
            CreateAction::make(),
            $this->provisionAction(),
            $this->exportAction(),
            $this->importAction(),
        ];
```

Add the private method (mirroring `exportAction()`'s shape):
```php
    private function provisionAction(): Action
    {
        return Action::make('provisionChart')
            ->label('Seed standard chart')
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription('Create a standard chart of accounts for this team. Skipped if accounts already exist.')
            ->action(function (TenantProvisioningService $service): void {
                $count = $service->provisionChartOfAccounts(Filament::getTenant());
                Notification::make()
                    ->title($count > 0 ? "Provisioned {$count} accounts." : 'Chart already exists; nothing added.')
                    ->success()
                    ->send();
            });
    }
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=ProvisionChartActionTest`
Expected: PASS. Then run the whole feature group: `docker compose exec -T php-fpm php artisan test --filter=Provisioning` → PASS.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Filament/App/Resources/ChartOfAccounts/Pages/ListChartOfAccounts.php tests/Feature/Provisioning/ProvisionChartActionTest.php
git -C src commit -m "feat(provisioning): seed-chart Filament action"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=Provisioning`. (No new tables/indexes here, but run it — it also catches model↔schema drift.)
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline (`--generate-baseline phpstan-baseline.neon`) only if the ONLY remaining errors are the Filament/Eloquent-`mixed` idiom on the new files — verify each before baselining.
- Pint the new files.
- Adversarial review focus: every provisioned account carries the team's `team_id` (never a default team 1) and the owner's `user_id`; the 18 numbers/types are correct and `normal_balance` is derived correctly for all 5 account types; idempotency (re-run adds nothing, doesn't partially duplicate); the batch is atomic (a mid-loop failure rolls back all); the Filament action provisions the CURRENT tenant only (`Filament::getTenant()`, no cross-tenant); unknown-team command path exits non-zero without side effects; that **no test disabled FK enforcement or edited phpunit.xml**.

## Self-Review

- **Spec coverage:** canonical chart in the service ✓ (T1 `const CHART`, 18 accounts); provision idempotent, team_id + user_id via forceFill, normal_balance derived ✓ (T1); explicit command ✓ (T2); Filament header action on the current tenant ✓ (T3). Deferred (backups, cloning, template/industry charts, auto-on-create) intentionally absent.
- **Placeholders:** none — T1/T2 full code; T3 gives the exact action code + imports + registration, pointing at the concrete in-repo file to mirror (`ListChartOfAccounts` existing actions) and a known-working panel test (`ApprovalRuleResourceTest`).
- **Type consistency:** `provisionChartOfAccounts(Team): int` identical across T1/T2/T3; account field names (`account_number`, `account_name`, `account_type`, `team_id`, `user_id`) consistent; the 18-count and the three spot-checked accounts (1100 AR/asset/debit, 2400 Deferred/liability/credit, 4000 Sales/revenue/credit) match the `CHART` const exactly.
