# Forecasting (Rolling + Scenarios) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Project a rolling multi-period baseline forecast from historical actuals and let named what-if scenarios adjust it by account-type percentage factors, with a baseline-vs-scenario comparison.

**Architecture:** `ForecastScenario` + `ForecastScenarioLine` models hold named scenarios with per-account-type `adjustment_pct`. A `ForecastService` builds a rolling baseline (moving average of each account's last-12 actuals, carried flat over N periods, aggregated by P&L account type), applies a scenario's factors, and compares the two. Filament gets a scenario resource + a comparison page.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- `ForecastScenario` uses `App\Traits\IsTenantModel` + a `creating` hook stamping `team_id` from `auth()->user()?->currentTeam` when empty.
- P&L account types are exactly `['revenue', 'cost_of_goods_sold', 'expense']` (verbatim — these are the values `FinancialStatementService` uses).
- Baseline math: per account, `avg` of the amounts from its most recent 12 `transactions` (ordered by `transaction_date desc`); carried flat across every future period; summed per account type. Missing/empty → `0.0`. Round money to 2 dp.
- Net income per period = `revenue − cost_of_goods_sold − expense`.
- Tests: sqlite `:memory:` ENFORCES FKs; **never disable FK enforcement / never edit phpunit.xml**. `Model::unguard()` is global. No `TeamFactory` — `Team::forceCreate(['user_id'=>$u->id,'name'=>'X','personal_team'=>false])`. `Account::factory()` exists (set `account_type` + `team_id`). Create transactions with `Transaction::create(['account_id'=>..,'transaction_date'=>..,'amount'=>..])`.
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: ForecastScenario + ForecastScenarioLine models & migrations

**Files:**
- Create: `src/database/migrations/2026_07_07_100001_create_forecast_scenarios_table.php`
- Create: `src/database/migrations/2026_07_07_100002_create_forecast_scenario_lines_table.php`
- Create: `src/app/Models/ForecastScenario.php`
- Create: `src/app/Models/ForecastScenarioLine.php`
- Test: `src/tests/Feature/Forecast/ForecastScenarioModelTest.php`

**Interfaces:**
- Produces: `ForecastScenario` (PK `id`; fillable `name, team_id`; `use IsTenantModel`; `lines()` = `hasMany(ForecastScenarioLine)`). `ForecastScenarioLine` (fillable `forecast_scenario_id, account_type, adjustment_pct`; `adjustment_pct` cast `decimal:2`).

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Forecast/ForecastScenarioModelTest.php
declare(strict_types=1);
namespace Tests\Feature\Forecast;

use App\Models\ForecastScenario;
use App\Models\ForecastScenarioLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastScenarioModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_has_type_factor_lines(): void
    {
        $scenario = ForecastScenario::create(['name' => 'Optimistic']);
        ForecastScenarioLine::create([
            'forecast_scenario_id' => $scenario->id, 'account_type' => 'revenue', 'adjustment_pct' => 10,
        ]);

        $this->assertCount(1, $scenario->fresh()->lines);
        $this->assertSame('revenue', $scenario->lines->first()->account_type);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=ForecastScenarioModelTest`
Expected: FAIL (`App\Models\ForecastScenario` not found).

- [ ] **Step 3: Create the migrations**

```php
<?php // 2026_07_07_100001_create_forecast_scenarios_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('forecast_scenarios', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->foreignId('team_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('forecast_scenarios'); }
};
```

```php
<?php // 2026_07_07_100002_create_forecast_scenario_lines_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('forecast_scenario_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('forecast_scenario_id')->constrained()->cascadeOnDelete();
            $t->string('account_type');
            $t->decimal('adjustment_pct', 8, 2)->default(0);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('forecast_scenario_lines'); }
};
```

- [ ] **Step 4: Create the models**

```php
<?php // src/app/Models/ForecastScenario.php
declare(strict_types=1);
namespace App\Models;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForecastScenario extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = ['name', 'team_id'];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (ForecastScenario $scenario): void {
            if (empty($scenario->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $scenario->team_id = $team->getKey();
            }
        });
    }

    public function lines(): HasMany { return $this->hasMany(ForecastScenarioLine::class); }
}
```

```php
<?php // src/app/Models/ForecastScenarioLine.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastScenarioLine extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = ['forecast_scenario_id', 'account_type', 'adjustment_pct'];

    #[\Override]
    protected $casts = ['adjustment_pct' => 'decimal:2'];

    public function scenario(): BelongsTo { return $this->belongsTo(ForecastScenario::class, 'forecast_scenario_id'); }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=ForecastScenarioModelTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add app/Models/ForecastScenario.php app/Models/ForecastScenarioLine.php database/migrations/2026_07_07_1000*.php tests/Feature/Forecast/ForecastScenarioModelTest.php
git -C src commit -m "feat(forecast): scenario models + migrations"
```

---

### Task 2: ForecastService — baseline, applyScenario, compare

**Files:**
- Create: `src/app/Services/ForecastService.php`
- Test: `src/tests/Feature/Forecast/ForecastServiceTest.php`

**Interfaces:**
- Consumes: `ForecastScenario` (Task 1), `App\Models\Account` (`account_type`, `team_id`, `transactions()`), `App\Models\Transaction` (`transaction_date`, `amount`, `account_id`).
- Produces:
  - `rollingBaseline(int $periods, ?int $teamId = null): array` → `[account_type => [1..periods => float]]` for `revenue`,`cost_of_goods_sold`,`expense`; each value = sum over that team+type's accounts of the account's last-12-transactions average, carried flat across periods; `teamId` defaults to `auth()->user()?->current_team_id ?? -1`.
  - `applyScenario(array $baseline, ForecastScenario $scenario): array` → baseline with each type multiplied by `(1 + adjustment_pct/100)` from the scenario's lines (types without a line unchanged).
  - `compare(int $periods, ForecastScenario $scenario, ?int $teamId = null): array` → `['baseline'=>…, 'scenario'=>…, 'baseline_net_income'=>[1..periods=>float], 'scenario_net_income'=>[…]]` where net = revenue − cost_of_goods_sold − expense.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Forecast/ForecastServiceTest.php
declare(strict_types=1);
namespace Tests\Feature\Forecast;

use App\Models\Account;
use App\Models\ForecastScenario;
use App\Models\ForecastScenarioLine;
use App\Models\Transaction;
use App\Services\ForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    private function seedTeamActuals(int $teamId): void
    {
        $revenue = Account::factory()->create(['team_id' => $teamId, 'account_type' => 'revenue']);
        $expense = Account::factory()->create(['team_id' => $teamId, 'account_type' => 'expense']);
        foreach ([100, 200, 300] as $i => $amt) {
            Transaction::create(['account_id' => $revenue->id, 'transaction_date' => "2026-0".($i + 1)."-01", 'amount' => $amt]);
        }
        Transaction::create(['account_id' => $expense->id, 'transaction_date' => '2026-01-01', 'amount' => 50]);
    }

    public function test_rolling_baseline_averages_actuals_per_type(): void
    {
        $this->seedTeamActuals(7);
        $baseline = app(ForecastService::class)->rollingBaseline(2, 7);

        // revenue avg (100+200+300)/3 = 200, carried across 2 periods; expense = 50; cogs = 0.
        $this->assertSame([1 => 200.0, 2 => 200.0], $baseline['revenue']);
        $this->assertSame([1 => 50.0, 2 => 50.0], $baseline['expense']);
        $this->assertSame([1 => 0.0, 2 => 0.0], $baseline['cost_of_goods_sold']);
    }

    public function test_apply_scenario_adjusts_by_type_factor(): void
    {
        $baseline = ['revenue' => [1 => 200.0], 'cost_of_goods_sold' => [1 => 0.0], 'expense' => [1 => 50.0]];
        $scenario = ForecastScenario::create(['name' => 'S']);
        ForecastScenarioLine::create(['forecast_scenario_id' => $scenario->id, 'account_type' => 'revenue', 'adjustment_pct' => 10]);
        ForecastScenarioLine::create(['forecast_scenario_id' => $scenario->id, 'account_type' => 'expense', 'adjustment_pct' => -20]);

        $adjusted = app(ForecastService::class)->applyScenario($baseline, $scenario);

        $this->assertSame([1 => 220.0], $adjusted['revenue']);   // +10%
        $this->assertSame([1 => 40.0], $adjusted['expense']);    // -20%
        $this->assertSame([1 => 0.0], $adjusted['cost_of_goods_sold']); // no line → unchanged
    }

    public function test_compare_reports_net_income_for_both(): void
    {
        $this->seedTeamActuals(7);
        $scenario = ForecastScenario::create(['name' => 'S', 'team_id' => 7]);
        ForecastScenarioLine::create(['forecast_scenario_id' => $scenario->id, 'account_type' => 'revenue', 'adjustment_pct' => 10]);

        $result = app(ForecastService::class)->compare(1, $scenario, 7);

        // baseline net = 200 - 0 - 50 = 150; scenario net = 220 - 0 - 50 = 170.
        $this->assertSame(150.0, $result['baseline_net_income'][1]);
        $this->assertSame(170.0, $result['scenario_net_income'][1]);
    }

    public function test_no_actuals_yields_zero(): void
    {
        $baseline = app(ForecastService::class)->rollingBaseline(1, 999);
        $this->assertSame([1 => 0.0], $baseline['revenue']);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=ForecastServiceTest`
Expected: FAIL (`App\Services\ForecastService` not found).

- [ ] **Step 3: Implement the service**

```php
<?php // src/app/Services/ForecastService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\Account;
use App\Models\ForecastScenario;

class ForecastService
{
    private const PL_TYPES = ['revenue', 'cost_of_goods_sold', 'expense'];

    /**
     * @return array<string, array<int, float>>
     */
    public function rollingBaseline(int $periods, ?int $teamId = null): array
    {
        $teamId ??= auth()->user()?->current_team_id ?? -1;
        $baseline = [];

        foreach (self::PL_TYPES as $type) {
            $total = 0.0;
            $accounts = Account::query()->where('team_id', $teamId)->where('account_type', $type)->get();
            foreach ($accounts as $account) {
                $avg = $account->transactions()
                    ->orderBy('transaction_date', 'desc')
                    ->limit(12)
                    ->pluck('amount')
                    ->avg();
                $total += (float) ($avg ?? 0);
            }
            $baseline[$type] = array_fill(1, max($periods, 1), round($total, 2));
        }

        return $baseline;
    }

    /**
     * @param  array<string, array<int, float>>  $baseline
     * @return array<string, array<int, float>>
     */
    public function applyScenario(array $baseline, ForecastScenario $scenario): array
    {
        $factors = $scenario->lines->pluck('adjustment_pct', 'account_type');
        $result = [];
        foreach ($baseline as $type => $periods) {
            $factor = 1 + ((float) ($factors[$type] ?? 0)) / 100;
            $result[$type] = array_map(static fn (float $v): float => round($v * $factor, 2), $periods);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(int $periods, ForecastScenario $scenario, ?int $teamId = null): array
    {
        $baseline = $this->rollingBaseline($periods, $teamId);
        $scenarioProjection = $this->applyScenario($baseline, $scenario);

        return [
            'baseline' => $baseline,
            'scenario' => $scenarioProjection,
            'baseline_net_income' => $this->netIncome($baseline, $periods),
            'scenario_net_income' => $this->netIncome($scenarioProjection, $periods),
        ];
    }

    /**
     * @param  array<string, array<int, float>>  $projection
     * @return array<int, float>
     */
    private function netIncome(array $projection, int $periods): array
    {
        $net = [];
        for ($p = 1; $p <= max($periods, 1); $p++) {
            $net[$p] = round(
                ($projection['revenue'][$p] ?? 0.0)
                    - ($projection['cost_of_goods_sold'][$p] ?? 0.0)
                    - ($projection['expense'][$p] ?? 0.0),
                2,
            );
        }

        return $net;
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=ForecastServiceTest`
Expected: PASS (4 tests). If `pluck('amount')->avg()` returns a string-typed avg, the `(float)` cast already handles it; if an account's `transactions()` relation name differs, read `app/Models/Account.php` — it is `transactions()`.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/ForecastService.php tests/Feature/Forecast/ForecastServiceTest.php
git -C src commit -m "feat(forecast): rolling baseline + scenario compare"
```

---

### Task 3: Filament ForecastScenarioResource + comparison page

**Files:**
- Create: `src/app/Filament/App/Resources/ForecastScenarios/ForecastScenarioResource.php`
- Create: `src/app/Filament/App/Resources/ForecastScenarios/Pages/{ListForecastScenarios,CreateForecastScenario,EditForecastScenario}.php`
- Create: `src/app/Filament/App/Pages/ForecastComparison.php`
- Create: `src/resources/views/filament/app/pages/forecast-comparison.blade.php`
- Test: `src/tests/Feature/Forecast/ForecastTenancyTest.php`

**Interfaces:**
- Consumes: `ForecastService` (Task 2), `ForecastScenario` (Task 1).
- Produces: a team-scoped `ForecastScenarioResource` (name + a `Repeater` of `ForecastScenarioLine` via the `lines` relationship: `account_type` Select of the three P&L types + `adjustment_pct` numeric) and a `ForecastComparison` page that runs `compare()` for a chosen scenario + period count and exposes the result array to a Blade view.

- [ ] **Step 1: Write the failing test (tenancy stamp)**

```php
<?php // src/tests/Feature/Forecast/ForecastTenancyTest.php
declare(strict_types=1);
namespace Tests\Feature\Forecast;

use App\Models\ForecastScenario;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_stamps_actor_team(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $scenario = ForecastScenario::create(['name' => 'Base case']);

        $this->assertSame($team->id, (int) $scenario->team_id);
    }
}
```

- [ ] **Step 2: Run it, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=ForecastTenancyTest`
Expected: PASS (Task 1's `creating` hook stamps `team_id`).

- [ ] **Step 3: Build the Filament resource**

Read an existing app-panel resource first — e.g. `src/app/Filament/App/Resources/ReconciliationRules/` or `ConsolidationGroups/` — and mirror its exact Filament v5 API (`Filament\Schemas\Schema`, `Filament\Forms\Components\Repeater`, table, `getPages`, `#[\Override]`). Create `ForecastScenarioResource` (`$model = ForecastScenario::class`, tenant-scoped by default). Form: `TextInput::make('name')->required()` + `Repeater::make('lines')->relationship()->schema([Select::make('account_type')->options(['revenue'=>'Revenue','cost_of_goods_sold'=>'Cost of Goods Sold','expense'=>'Expense'])->required(), TextInput::make('adjustment_pct')->numeric()->default(0)->suffix('%')->required()])`. Table columns: `name`, `lines_count` (`counts('lines')`). Standard List/Create/Edit pages.

- [ ] **Step 4: Build the comparison page**

Read an existing custom page (e.g. `src/app/Filament/App/Pages/ConsolidatedReports.php`) to match the v5 page API. Create `ForecastComparison` with a form (`Select::make('forecast_scenario_id')` scoped to the current tenant's scenarios, `TextInput::make('periods')->numeric()->default(3)`), a `generate()` action that calls `app(ForecastService::class)->compare((int) $this->data['periods'], ForecastScenario::findOrFail($this->data['forecast_scenario_id']))` and stores the result in a public `?array $result` property, and a minimal Blade view rendering `$result['baseline_net_income']` vs `$result['scenario_net_income']` per period. Gate the scenario Select to the current team (`ForecastScenario::where('team_id', \Filament\Facades\Filament::getTenant()?->id)->pluck('name','id')`).

- [ ] **Step 5: Run all forecast tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=Forecast`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add app/Filament/App/Resources/ForecastScenarios app/Filament/App/Pages/ForecastComparison.php resources/views/filament/app/pages/forecast-comparison.blade.php tests/Feature/Forecast/ForecastTenancyTest.php
git -C src commit -m "feat(forecast): Filament scenario resource + compare page"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=Forecast`.
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline if only Filament/Eloquent-`mixed` idiom errors remain.
- Pint the new files.
- Adversarial review focus: baseline scoping (only the team's accounts, correct `last-12` window), scenario factor math (percent sign + unchanged types), net-income formula, tenancy on scenarios + the comparison page's scenario list, and that **no test disabled FK enforcement or edited phpunit.xml**.

## Self-Review

- **Spec coverage:** ForecastScenario + line models ✓ (T1); rolling baseline (moving-avg, per type, N periods) ✓ (T2); applyScenario type-factor math ✓ (T2); compare + net income ✓ (T2); no-actuals→0 ✓ (T2); Filament resource + comparison page ✓ (T3); tenancy ✓ (T3 test + T1 hook). Deferred items (seasonality/trend/Monte Carlo) intentionally absent.
- **Placeholders:** none — every code step has real code; T3 Steps 3-4 point to concrete in-repo resources/pages to mirror for the Filament-v5 boilerplate, with the behavioral parts (fields, options, the `compare()` call, gating) given verbatim.
- **Type consistency:** `rollingBaseline(int,?int): array`, `applyScenario(array, ForecastScenario): array`, `compare(int, ForecastScenario, ?int): array` used identically across T2/T3; `lines()` relation + `account_type`/`adjustment_pct` column names consistent T1↔T2↔T3; PL types list identical everywhere.
