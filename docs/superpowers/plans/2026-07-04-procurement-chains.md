# Procurement Chains Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `PurchaseRequest` (requisition) that runs through the existing approval engine and, once approved, converts to a `PurchaseOrder`.

**Architecture:** New `PurchaseRequest` + `PurchaseRequestItem` models (default `id` PK, `IsTenantModel`, `Approvable`). Approval + spend control reuse the existing `Approvable` engine (`submitForApproval()` → `ApprovalRule` amount thresholds). A `ProcurementService` converts an approved request to a `PurchaseOrder` (approved-only, once-per-request), copying supplier + items + total and linking `purchase_orders.purchase_request_id`. Mirrors the just-shipped sales-order lifecycle.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- New models use `App\Traits\IsTenantModel` + a `creating` hook stamping `team_id` from `auth()->user()?->currentTeam` when empty.
- Money columns `decimal(15,2)`. Copy amounts verbatim across the conversion.
- `PurchaseRequest` implements `Approvable::approvalAmount(): float` returning `(float) $this->total_amount`; it needs the approval columns (`approval_status`, `approved_by`, `approved_at`, `rejection_reason`) — same set `bills`/`invoices` have.
- Tests: sqlite `:memory:` ENFORCES FKs; `Model::unguard()` is global. No `TeamFactory` — `Team::forceCreate(['user_id'=>$u->id,'name'=>'X','personal_team'=>false])`. `invoices.team_id`/similar have real FKs, but `purchase_requests.team_id`/`purchase_orders` do not constrain team_id, so arbitrary ints are fine there.
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: PurchaseRequest + PurchaseRequestItem models, migrations, PO link

**Files:**
- Create: `src/database/migrations/2026_07_06_100001_create_purchase_requests_table.php`
- Create: `src/database/migrations/2026_07_06_100002_create_purchase_request_items_table.php`
- Create: `src/database/migrations/2026_07_06_100003_add_purchase_request_id_to_purchase_orders_table.php`
- Create: `src/app/Models/PurchaseRequest.php`
- Create: `src/app/Models/PurchaseRequestItem.php`
- Modify: `src/app/Models/PurchaseOrder.php` (fillable + `purchaseRequest()` relation)
- Test: `src/tests/Feature/Procurement/PurchaseRequestModelTest.php`

**Interfaces:**
- Produces: `PurchaseRequest` (PK `id`; fillable `supplier_id, request_number, request_date, total_amount, status, notes, team_id, approval_status, approved_by, approved_at, rejection_reason`; `use Approvable, IsTenantModel`; `approvalAmount(): float`; relations `supplier()`, `items()` = `hasMany(PurchaseRequestItem)`, `purchaseOrder()` = `hasOne(PurchaseOrder, 'purchase_request_id')`). `PurchaseRequestItem` (fillable `purchase_request_id, description, quantity, unit_price, total_price`). `PurchaseOrder::purchaseRequest(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Procurement/PurchaseRequestModelTest.php
declare(strict_types=1);
namespace Tests\Feature\Procurement;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_persists_with_items_and_generates_number(): void
    {
        $request = PurchaseRequest::create([
            'request_date' => '2026-07-01', 'status' => 'draft', 'total_amount' => 100,
        ]);
        PurchaseRequestItem::create([
            'purchase_request_id' => $request->id, 'description' => 'Widget',
            'quantity' => 1, 'unit_price' => 100, 'total_price' => 100,
        ]);

        $this->assertNotEmpty($request->request_number);
        $this->assertCount(1, $request->fresh()->items);
        $this->assertSame(100.0, $request->approvalAmount());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=PurchaseRequestModelTest`
Expected: FAIL (`App\Models\PurchaseRequest` not found).

- [ ] **Step 3: Create the migrations**

```php
<?php // 2026_07_06_100001_create_purchase_requests_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('purchase_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('supplier_id')->nullable()->constrained('suppliers', 'supplier_id')->nullOnDelete();
            $t->string('request_number')->unique();
            $t->date('request_date');
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->string('status')->default('draft');
            $t->text('notes')->nullable();
            $t->string('approval_status')->default('pending');
            $t->foreignId('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreignId('team_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('purchase_requests'); }
};
```

```php
<?php // 2026_07_06_100002_create_purchase_request_items_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('purchase_request_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('purchase_request_id')->constrained('purchase_requests')->cascadeOnDelete();
            $t->string('description')->nullable();
            $t->integer('quantity')->default(1);
            $t->decimal('unit_price', 15, 2)->default(0);
            $t->decimal('total_price', 15, 2)->default(0);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('purchase_request_items'); }
};
```

```php
<?php // 2026_07_06_100003_add_purchase_request_id_to_purchase_orders_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('purchase_orders', function (Blueprint $t): void {
            $t->foreignId('purchase_request_id')->nullable()->unique()->after('purchase_order_id')
                ->constrained('purchase_requests')->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table('purchase_orders', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('purchase_request_id');
        });
    }
};
```

- [ ] **Step 4: Create the models**

```php
<?php // src/app/Models/PurchaseRequest.php
declare(strict_types=1);
namespace App\Models;
use App\Concerns\Approvable;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseRequest extends Model
{
    use Approvable;
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = [
        'supplier_id', 'request_number', 'request_date', 'total_amount',
        'status', 'notes', 'team_id',
        'approval_status', 'approved_by', 'approved_at', 'rejection_reason',
    ];

    #[\Override]
    protected $casts = [
        'request_date' => 'date',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (PurchaseRequest $request): void {
            if (empty($request->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $request->team_id = $team->getKey();
            }
            if (empty($request->request_number)) {
                $request->request_number = 'PR-'.str_pad((string) ((int) static::max('id') + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function approvalAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id'); }
    public function items(): HasMany { return $this->hasMany(PurchaseRequestItem::class); }
    public function purchaseOrder(): HasOne { return $this->hasOne(PurchaseOrder::class, 'purchase_request_id'); }
}
```

```php
<?php // src/app/Models/PurchaseRequestItem.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'purchase_request_id', 'description', 'quantity', 'unit_price', 'total_price',
    ];

    #[\Override]
    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function purchaseRequest(): BelongsTo { return $this->belongsTo(PurchaseRequest::class); }
}
```

- [ ] **Step 5: Wire the PurchaseOrder relation**

In `src/app/Models/PurchaseOrder.php`: add `'purchase_request_id',` to `$fillable`, and add:

```php
public function purchaseRequest()
{
    return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
}
```

- [ ] **Step 6: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=PurchaseRequestModelTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git -C src add app/Models/PurchaseRequest.php app/Models/PurchaseRequestItem.php app/Models/PurchaseOrder.php database/migrations/2026_07_06_1000*.php tests/Feature/Procurement/PurchaseRequestModelTest.php
git -C src commit -m "feat(procurement): PurchaseRequest models + PO link"
```

---

### Task 2: Approval / spend-control wiring

**Files:**
- Modify: `src/app/Filament/App/Resources/ApprovalRules/ApprovalRuleResource.php:53-56` (add `PurchaseRequest` option)
- Test: `src/tests/Feature/Procurement/PurchaseRequestApprovalTest.php`

**Interfaces:**
- Consumes: `PurchaseRequest` (Task 1), the existing `Approvable::submitForApproval()` + `App\Models\ApprovalRule`.
- Produces: nothing new — verifies that `submitForApproval()` auto-approves a request when no spend rule matches and routes to `pending` when a threshold rule applies.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Procurement/PurchaseRequestApprovalTest.php
declare(strict_types=1);
namespace Tests\Feature\Procurement;

use App\Models\ApprovalRule;
use App\Models\PurchaseRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_auto_approves_when_no_spend_rule_matches(): void
    {
        $request = PurchaseRequest::create(['request_date' => '2026-07-01', 'total_amount' => 500, 'status' => 'draft']);

        $request->submitForApproval();

        $this->assertSame('approved', $request->fresh()->approval_status);
    }

    public function test_request_over_threshold_routes_to_pending(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        ApprovalRule::create([
            'team_id' => $team->id, 'approvable_type' => 'PurchaseRequest',
            'min_amount' => 100, 'steps' => ['manager'], 'is_active' => true,
        ]);

        $request = PurchaseRequest::create([
            'request_date' => '2026-07-01', 'total_amount' => 500, 'status' => 'draft', 'team_id' => $team->id,
        ]);

        $request->submitForApproval();

        $this->assertSame('pending', $request->fresh()->approval_status);
    }
}
```

- [ ] **Step 2: Run it, verify it fails or passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=PurchaseRequestApprovalTest`
Expected: the first test PASSES already (no rule → auto-approve is trait behavior). The second may FAIL if `ApprovalRule::create` needs columns you must match — read `app/Models/ApprovalRule.php` `$fillable` and the `approval_rules` migration, and adjust the `create([...])` keys in the test to the real column names (e.g. `steps` may be a JSON/cast column named differently). Fix the test to match the real schema, then it should pass. If `submitForApproval()`'s `matchFor` compares `class_basename` (`PurchaseRequest`), the `approvable_type` value `'PurchaseRequest'` is correct.

- [ ] **Step 3: Register the type option**

In `ApprovalRuleResource.php`, in `approvableTypeOptions()`, add after `'JournalEntry' => 'Journal Entry',`:

```php
            'PurchaseRequest' => 'Purchase Request',
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=PurchaseRequestApprovalTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Filament/App/Resources/ApprovalRules/ApprovalRuleResource.php tests/Feature/Procurement/PurchaseRequestApprovalTest.php
git -C src commit -m "feat(procurement): route requests through approval"
```

---

### Task 3: ProcurementService::createPurchaseOrderFromRequest

**Files:**
- Create: `src/app/Services/ProcurementService.php`
- Test: `src/tests/Feature/Procurement/CreatePurchaseOrderTest.php`

**Interfaces:**
- Consumes: `PurchaseRequest`, `PurchaseRequestItem` (Task 1), `PurchaseOrder`, `PurchaseOrderItem`.
- Produces: `ProcurementService::createPurchaseOrderFromRequest(PurchaseRequest $request): PurchaseOrder` — throws `\DomainException` when the request is not `approved` (approval_status) or already has a purchase order. Copies `supplier_id`, `total_amount`; each `PurchaseRequestItem` (`description, quantity, unit_price, total_price`) → `PurchaseOrderItem`; sets PO `status='draft'`, `order_date=today()`, `po_number=PurchaseOrder::generatePoNumber()`, `purchase_request_id`, `team_id`. Returns the persisted `PurchaseOrder`.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Procurement/CreatePurchaseOrderTest.php
declare(strict_types=1);
namespace Tests\Feature\Procurement;

use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Services\ProcurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private function approvedRequest(): PurchaseRequest
    {
        $request = PurchaseRequest::create([
            'request_date' => '2026-07-01', 'total_amount' => 300,
            'status' => 'draft', 'approval_status' => 'approved', 'team_id' => 7,
        ]);
        PurchaseRequestItem::create([
            'purchase_request_id' => $request->id, 'description' => 'Steel',
            'quantity' => 3, 'unit_price' => 100, 'total_price' => 300,
        ]);
        return $request;
    }

    public function test_approved_request_converts_to_purchase_order(): void
    {
        $request = $this->approvedRequest();
        $po = app(ProcurementService::class)->createPurchaseOrderFromRequest($request);

        $this->assertSame($request->id, $po->purchase_request_id);
        $this->assertSame('300.00', (string) $po->total_amount);
        $this->assertSame('draft', $po->status);
        $this->assertNotEmpty($po->po_number);
        $this->assertSame(7, (int) $po->team_id);
        $this->assertCount(1, $po->items);
        $this->assertSame('Steel', $po->items->first()->description);
    }

    public function test_unapproved_request_is_rejected(): void
    {
        $request = $this->approvedRequest();
        $request->update(['approval_status' => 'pending']);

        $this->expectException(\DomainException::class);
        app(ProcurementService::class)->createPurchaseOrderFromRequest($request);
    }

    public function test_request_cannot_convert_twice(): void
    {
        $request = $this->approvedRequest();
        app(ProcurementService::class)->createPurchaseOrderFromRequest($request);

        $this->expectException(\DomainException::class);
        app(ProcurementService::class)->createPurchaseOrderFromRequest($request->fresh());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=CreatePurchaseOrderTest`
Expected: FAIL (`App\Services\ProcurementService` not found).

- [ ] **Step 3: Implement the service**

```php
<?php // src/app/Services/ProcurementService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use Illuminate\Support\Facades\DB;

class ProcurementService
{
    public function createPurchaseOrderFromRequest(PurchaseRequest $request): PurchaseOrder
    {
        if ($request->approval_status !== 'approved') {
            throw new \DomainException('Only an approved purchase request can become a purchase order.');
        }
        if ($request->purchaseOrder()->exists()) {
            throw new \DomainException('This request already has a purchase order.');
        }

        return DB::transaction(function () use ($request): PurchaseOrder {
            $po = PurchaseOrder::create([
                'supplier_id' => $request->supplier_id,
                'purchase_request_id' => $request->id,
                'po_number' => PurchaseOrder::generatePoNumber(),
                'order_date' => today(),
                'status' => 'draft',
                'total_amount' => $request->total_amount,
                'team_id' => $request->team_id,
            ]);

            foreach ($request->items as $item) {
                $po->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                ]);
            }

            return $po;
        });
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=CreatePurchaseOrderTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/ProcurementService.php tests/Feature/Procurement/CreatePurchaseOrderTest.php
git -C src commit -m "feat(procurement): convert approved request to PO"
```

---

### Task 4: Filament PurchaseRequestResource + submit/convert actions

**Files:**
- Create: `src/app/Filament/App/Resources/PurchaseRequests/PurchaseRequestResource.php`
- Create: `src/app/Filament/App/Resources/PurchaseRequests/Pages/{ListPurchaseRequests,CreatePurchaseRequest,EditPurchaseRequest}.php`
- Test: `src/tests/Feature/Procurement/PurchaseRequestTenancyTest.php`

**Interfaces:**
- Consumes: `ProcurementService` (Task 3), `PurchaseRequest` (Task 1), `Approvable::submitForApproval()`.
- Produces: a team-scoped Filament resource with two row actions — `submitForApproval` (visible when `approval_status !== 'approved'`) calling `$record->submitForApproval()`, and `convertToPurchaseOrder` (visible when `approval_status === 'approved'` and no PO yet) calling `ProcurementService::createPurchaseOrderFromRequest`, each wrapped in `try { … } catch (\DomainException $e) { danger notification($e->getMessage()) } catch (\Throwable) { danger notification('…') }`.

- [ ] **Step 1: Write the failing test (tenancy stamp)**

```php
<?php // src/tests/Feature/Procurement/PurchaseRequestTenancyTest.php
declare(strict_types=1);
namespace Tests\Feature\Procurement;

use App\Models\PurchaseRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_stamps_actor_team_on_create(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $request = PurchaseRequest::create(['request_date' => '2026-07-01', 'total_amount' => 10, 'status' => 'draft']);

        $this->assertSame($team->id, (int) $request->team_id);
    }
}
```

- [ ] **Step 2: Run it, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=PurchaseRequestTenancyTest`
Expected: PASS (Task 1's `creating` hook stamps `team_id`). Keep it as a guard.

- [ ] **Step 3: Build the Filament resource**

Read an existing app-panel resource first — `src/app/Filament/App/Resources/PurchaseOrders/PurchaseOrderResource.php` and its `Pages/` — and mirror its exact Filament v5 API (schema/form, table, `getPages`, `recordActions`, `#[\Override]`, navigation). Create `PurchaseRequestResource` (`$model = PurchaseRequest::class`, tenant-scoped by default). Table: `request_number`, `request_date`, `total_amount` (money), `approval_status` (badge). Two row actions:

```php
\Filament\Actions\Action::make('submitForApproval')
    ->label('Submit for approval')
    ->requiresConfirmation()
    ->visible(fn (\App\Models\PurchaseRequest $r): bool => $r->approval_status !== 'approved')
    ->action(function (\App\Models\PurchaseRequest $r): void {
        try {
            $r->submitForApproval();
            \Filament\Notifications\Notification::make()->title('Submitted for approval')->success()->send();
        } catch (\Throwable) {
            \Filament\Notifications\Notification::make()->title('Could not submit. Please retry.')->danger()->send();
        }
    }),
\Filament\Actions\Action::make('convertToPurchaseOrder')
    ->label('Convert to Purchase Order')
    ->requiresConfirmation()
    ->visible(fn (\App\Models\PurchaseRequest $r): bool => $r->approval_status === 'approved' && ! $r->purchaseOrder()->exists())
    ->action(function (\App\Models\PurchaseRequest $r): void {
        try {
            app(\App\Services\ProcurementService::class)->createPurchaseOrderFromRequest($r);
            \Filament\Notifications\Notification::make()->title('Purchase order created')->success()->send();
        } catch (\DomainException $e) {
            \Filament\Notifications\Notification::make()->title($e->getMessage())->danger()->send();
        } catch (\Throwable) {
            \Filament\Notifications\Notification::make()->title('Could not create the purchase order. Please retry.')->danger()->send();
        }
    }),
```

Make the `approval_status` form field read-only (`->disabled()->dehydrated(false)`) — it is system-managed by the approval engine. Create the three Pages (List/Create/Edit) mirroring an existing resource's Pages.

- [ ] **Step 4: Run all procurement tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=Procurement`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Filament/App/Resources/PurchaseRequests tests/Feature/Procurement/PurchaseRequestTenancyTest.php
git -C src commit -m "feat(procurement): Filament request resource + actions"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=Procurement`.
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline if only Filament/Eloquent-`mixed` idiom errors remain.
- Pint the new files.
- Adversarial review focus: convert guard bypass (unapproved / already-converted), spend-control bypass (converting a request that skipped `submitForApproval`), tenancy stamping on the created PO, item/total copy correctness, and whether the `approval_status` field is truly system-managed (not hand-editable to `approved`).

## Self-Review

- **Spec coverage:** PurchaseRequest + item models ✓ (T1); Approvable + approvalAmount ✓ (T1); spend control via ApprovalRule thresholds + type option ✓ (T2); approved-only + once convert with `purchase_request_id` link + unique index ✓ (T3 + T1 migration); supplier/items/total copy ✓ (T3); Filament resource + submit + convert actions + system-managed status ✓ (T4); tenancy ✓ (T4 test + T1 hook); PO→Bill untouched ✓. Deferred (goods receipt / 3-way match) intentionally absent.
- **Placeholders:** none — each step has real code/commands. T2 Step 2 asks the implementer to reconcile the test's `ApprovalRule::create` keys with the real `approval_rules` schema because that table was built in P1-1 and its exact column names (esp. the `steps` cast) must be matched, not guessed.
- **Type consistency:** `createPurchaseOrderFromRequest(PurchaseRequest): PurchaseOrder` used identically in T3/T4; `purchaseOrder()` (hasOne) / `purchaseRequest()` (belongsTo) relation names consistent; `PurchaseRequestItem` uses `total_price` (matching `PurchaseOrderItem`), copied verbatim in T3.
