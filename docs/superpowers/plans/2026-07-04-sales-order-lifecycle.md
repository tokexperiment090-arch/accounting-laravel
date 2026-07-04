# Sales-Order Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Insert a `SalesOrder` between the existing `Estimate` (quote) and `Invoice`, with a guarded conversion flow accepted-Estimate → SalesOrder → Invoice.

**Architecture:** New `SalesOrder` + `SalesOrderItem` models (default `id` PK, `IsTenantModel`). A `SalesOrderService` owns the two conversions with accepted-only + once-each guards, copying line items and totals and linking rows back (`sales_orders.estimate_id`, `invoices.sales_order_id`). The existing direct `Estimate::convertToInvoice()` path is left untouched — this is additive. Filament gets a `SalesOrderResource` plus convert actions on Estimate and SalesOrder.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Run tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if a run says "service not running", `docker compose up -d php-fpm` then retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on any method/property overriding a parent.
- New models use `App\Traits\IsTenantModel` (team relation only, no global scope) + a `creating` hook stamping `team_id` from `auth()->user()?->currentTeam` when empty (mirror `JournalEntry`).
- Tests: sqlite `:memory:` ENFORCES FKs; build real related rows (Customer/Estimate factories exist). `Model::unguard()` is global, so `create()` ignores `$fillable`. No `TeamFactory` — use `Team::forceCreate(['user_id'=>$u->id,'name'=>'X','personal_team'=>false])`.
- Money columns `decimal(15,2)`. Copy amounts verbatim across conversions (no recompute this increment).
- Commit after each task with a Conventional-Commits subject ≤50 chars.

---

### Task 1: SalesOrder + SalesOrderItem models, migrations, invoices link

**Files:**
- Create: `src/database/migrations/2026_07_05_100001_create_sales_orders_table.php`
- Create: `src/database/migrations/2026_07_05_100002_create_sales_order_items_table.php`
- Create: `src/database/migrations/2026_07_05_100003_add_sales_order_id_to_invoices_table.php`
- Create: `src/app/Models/SalesOrder.php`
- Create: `src/app/Models/SalesOrderItem.php`
- Modify: `src/app/Models/Invoice.php` (fillable + `salesOrder()` relation)
- Modify: `src/app/Models/Estimate.php` (`salesOrder()` hasOne)
- Test: `src/tests/Feature/SalesOrder/SalesOrderModelTest.php`

**Interfaces:**
- Produces: `SalesOrder` (PK `id`; fillable `customer_id, estimate_id, sales_order_number, order_date, subtotal_amount, tax_amount, total_amount, status, notes, team_id`; relations `customer()`, `estimate()`, `items()`, `invoice()`; `items()` = `hasMany(SalesOrderItem::class)`). `SalesOrderItem` (fillable `sales_order_id, account_id, description, quantity, unit_price, amount, tax_amount, tax_rate_id`). `Estimate::salesOrder(): HasOne`. `Invoice::salesOrder(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/SalesOrder/SalesOrderModelTest.php
declare(strict_types=1);
namespace Tests\Feature\SalesOrder;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_order_persists_with_items_and_generates_number(): void
    {
        $so = SalesOrder::create([
            'order_date' => '2026-07-01', 'status' => 'draft',
            'subtotal_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
        ]);
        SalesOrderItem::create([
            'sales_order_id' => $so->id, 'description' => 'Widget',
            'quantity' => 1, 'unit_price' => 100, 'amount' => 100, 'tax_amount' => 0,
        ]);

        $this->assertNotEmpty($so->sales_order_number);          // auto-generated
        $this->assertCount(1, $so->fresh()->items);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=SalesOrderModelTest`
Expected: FAIL (class `App\Models\SalesOrder` not found).

- [ ] **Step 3: Create the migrations**

```php
<?php // 2026_07_05_100001_create_sales_orders_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('sales_orders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->foreignId('estimate_id')->nullable()->unique()->constrained('estimates', 'estimate_id')->nullOnDelete();
            $t->string('sales_order_number')->unique();
            $t->date('order_date');
            $t->decimal('subtotal_amount', 15, 2)->default(0);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->string('status')->default('draft'); // draft, confirmed, invoiced, cancelled
            $t->text('notes')->nullable();
            $t->foreignId('team_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('sales_orders'); }
};
```

```php
<?php // 2026_07_05_100002_create_sales_order_items_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('sales_order_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $t->foreignId('account_id')->nullable();
            $t->string('description')->nullable();
            $t->integer('quantity')->default(1);
            $t->decimal('unit_price', 15, 2)->default(0);
            $t->decimal('amount', 15, 2)->default(0);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->foreignId('tax_rate_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('sales_order_items'); }
};
```

```php
<?php // 2026_07_05_100003_add_sales_order_id_to_invoices_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('invoices', function (Blueprint $t): void {
            $t->foreignId('sales_order_id')->nullable()->unique()->after('customer_id')
                ->constrained('sales_orders')->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table('invoices', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('sales_order_id');
        });
    }
};
```

- [ ] **Step 4: Create the models**

```php
<?php // src/app/Models/SalesOrder.php
declare(strict_types=1);
namespace App\Models;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalesOrder extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = [
        'customer_id', 'estimate_id', 'sales_order_number', 'order_date',
        'subtotal_amount', 'tax_amount', 'total_amount', 'status', 'notes', 'team_id',
    ];

    #[\Override]
    protected $casts = [
        'order_date' => 'date',
        'subtotal_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (SalesOrder $order): void {
            if (empty($order->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $order->team_id = $team->getKey();
            }
            if (empty($order->sales_order_number)) {
                $order->sales_order_number = 'SO-'.str_pad((string) ((int) static::max('id') + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function estimate(): BelongsTo { return $this->belongsTo(Estimate::class, 'estimate_id', 'estimate_id'); }
    public function items(): HasMany { return $this->hasMany(SalesOrderItem::class); }
    public function invoice(): HasOne { return $this->hasOne(Invoice::class, 'sales_order_id'); }

    public function confirm(): void { $this->update(['status' => 'confirmed']); }
    public function cancel(): void { $this->update(['status' => 'cancelled']); }
}
```

```php
<?php // src/app/Models/SalesOrderItem.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderItem extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'sales_order_id', 'account_id', 'description',
        'quantity', 'unit_price', 'amount', 'tax_amount', 'tax_rate_id',
    ];

    #[\Override]
    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo { return $this->belongsTo(SalesOrder::class); }
}
```

- [ ] **Step 5: Wire relations on Estimate + Invoice**

In `src/app/Models/Estimate.php` add (near the other relations, and import `Illuminate\Database\Eloquent\Relations\HasOne`):

```php
public function salesOrder(): HasOne
{
    return $this->hasOne(SalesOrder::class, 'estimate_id', 'estimate_id');
}
```

In `src/app/Models/Invoice.php`: add `'sales_order_id',` to `$fillable` (after `'customer_id',`) and add:

```php
public function salesOrder()
{
    return $this->belongsTo(SalesOrder::class, 'sales_order_id');
}
```

- [ ] **Step 6: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=SalesOrderModelTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git -C src add app/Models/SalesOrder.php app/Models/SalesOrderItem.php app/Models/Invoice.php app/Models/Estimate.php database/migrations/2026_07_05_1000*.php tests/Feature/SalesOrder/SalesOrderModelTest.php
git -C src commit -m "feat(sales-order): models + migrations + links"
```

---

### Task 2: SalesOrderService::createFromEstimate

**Files:**
- Create: `src/app/Services/SalesOrderService.php`
- Test: `src/tests/Feature/SalesOrder/CreateFromEstimateTest.php`

**Interfaces:**
- Consumes: `SalesOrder`, `SalesOrderItem`, `Estimate` (Task 1).
- Produces: `SalesOrderService::createFromEstimate(Estimate $estimate): SalesOrder` — throws `\DomainException` when the estimate is not `accepted` or already has a sales order. Copies `customer_id` + the three amount columns; copies each `EstimateItem` (`description, quantity, unit_price, amount, tax_amount, tax_rate_id`) into a `SalesOrderItem`; sets `status = 'confirmed'`, `order_date = today()`, `estimate_id`. Returns the persisted `SalesOrder`.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/SalesOrder/CreateFromEstimateTest.php
declare(strict_types=1);
namespace Tests\Feature\SalesOrder;

use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateFromEstimateTest extends TestCase
{
    use RefreshDatabase;

    private function acceptedEstimate(): Estimate
    {
        $customer = Customer::factory()->create();
        $estimate = Estimate::create([
            'customer_id' => $customer->id, 'estimate_number' => 'EST-1',
            'estimate_date' => '2026-06-01', 'subtotal_amount' => 200,
            'tax_amount' => 20, 'total_amount' => 220, 'status' => 'accepted',
        ]);
        EstimateItem::create([
            'estimate_id' => $estimate->estimate_id, 'description' => 'Consulting',
            'quantity' => 2, 'unit_price' => 100, 'amount' => 200, 'tax_amount' => 20,
        ]);
        return $estimate;
    }

    public function test_accepted_estimate_converts_to_confirmed_sales_order(): void
    {
        $estimate = $this->acceptedEstimate();
        $so = app(SalesOrderService::class)->createFromEstimate($estimate);

        $this->assertSame('confirmed', $so->status);
        $this->assertSame($estimate->estimate_id, $so->estimate_id);
        $this->assertSame($estimate->customer_id, $so->customer_id);
        $this->assertSame('220.00', (string) $so->total_amount);
        $this->assertCount(1, $so->items);
        $this->assertSame('Consulting', $so->items->first()->description);
    }

    public function test_non_accepted_estimate_is_rejected(): void
    {
        $estimate = $this->acceptedEstimate();
        $estimate->update(['status' => 'sent']);

        $this->expectException(\DomainException::class);
        app(SalesOrderService::class)->createFromEstimate($estimate);
    }

    public function test_estimate_cannot_convert_twice(): void
    {
        $estimate = $this->acceptedEstimate();
        app(SalesOrderService::class)->createFromEstimate($estimate);

        $this->expectException(\DomainException::class);
        app(SalesOrderService::class)->createFromEstimate($estimate->fresh());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=CreateFromEstimateTest`
Expected: FAIL (`App\Services\SalesOrderService` not found).

- [ ] **Step 3: Implement the service method**

```php
<?php // src/app/Services/SalesOrderService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\Estimate;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function createFromEstimate(Estimate $estimate): SalesOrder
    {
        if ($estimate->status !== 'accepted') {
            throw new \DomainException('Only an accepted estimate can become a sales order.');
        }
        if ($estimate->salesOrder()->exists()) {
            throw new \DomainException('This estimate already has a sales order.');
        }

        return DB::transaction(function () use ($estimate): SalesOrder {
            $order = SalesOrder::create([
                'customer_id' => $estimate->customer_id,
                'estimate_id' => $estimate->estimate_id,
                'order_date' => today(),
                'status' => 'confirmed',
                'subtotal_amount' => $estimate->subtotal_amount,
                'tax_amount' => $estimate->tax_amount,
                'total_amount' => $estimate->total_amount,
            ]);

            foreach ($estimate->items as $item) {
                $order->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'tax_amount' => $item->tax_amount,
                    'tax_rate_id' => $item->tax_rate_id,
                ]);
            }

            return $order;
        });
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=CreateFromEstimateTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/SalesOrderService.php tests/Feature/SalesOrder/CreateFromEstimateTest.php
git -C src commit -m "feat(sales-order): convert accepted estimate to SO"
```

---

### Task 3: SalesOrderService::convertToInvoice

**Files:**
- Modify: `src/app/Services/SalesOrderService.php` (add method)
- Test: `src/tests/Feature/SalesOrder/ConvertToInvoiceTest.php`

**Interfaces:**
- Consumes: `SalesOrder`, `Invoice`, `InvoiceItem` (Tasks 1 + existing).
- Produces: `SalesOrderService::convertToInvoice(SalesOrder $order): Invoice` — throws `\DomainException` when `status` ∈ {`invoiced`,`cancelled`} or the order already has an invoice. Creates an `Invoice` (`customer_id`, `sales_order_id`, `invoice_date = today()`, `due_date = today()+30`, `total_amount`, `payment_status='pending'`; `invoice_number` left null → the model's creating hook fills it). Copies each `SalesOrderItem` into an `InvoiceItem`. Sets the order's `status = 'invoiced'`. Returns the persisted `Invoice`.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/SalesOrder/ConvertToInvoiceTest.php
declare(strict_types=1);
namespace Tests\Feature\SalesOrder;

use App\Models\Customer;
use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConvertToInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function confirmedOrder(): SalesOrder
    {
        $customer = Customer::factory()->create();
        $order = SalesOrder::create([
            'customer_id' => $customer->id, 'order_date' => '2026-07-01',
            'status' => 'confirmed', 'subtotal_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100,
        ]);
        $order->items()->create([
            'description' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'amount' => 100, 'tax_amount' => 0,
        ]);
        return $order;
    }

    public function test_converts_to_invoice_and_marks_order_invoiced(): void
    {
        $order = $this->confirmedOrder();
        $invoice = app(SalesOrderService::class)->convertToInvoice($order);

        $this->assertSame($order->id, $invoice->sales_order_id);
        $this->assertSame($order->customer_id, $invoice->customer_id);
        $this->assertSame('100.00', (string) $invoice->total_amount);
        $this->assertNotEmpty($invoice->invoice_number);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('invoiced', $order->fresh()->status);
    }

    public function test_cannot_invoice_twice(): void
    {
        $order = $this->confirmedOrder();
        app(SalesOrderService::class)->convertToInvoice($order);

        $this->expectException(\DomainException::class);
        app(SalesOrderService::class)->convertToInvoice($order->fresh());
    }

    public function test_cannot_invoice_a_cancelled_order(): void
    {
        $order = $this->confirmedOrder();
        $order->cancel();

        $this->expectException(\DomainException::class);
        app(SalesOrderService::class)->convertToInvoice($order->fresh());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=ConvertToInvoiceTest`
Expected: FAIL (`convertToInvoice` not defined).

- [ ] **Step 3: Add the method to SalesOrderService**

Add these imports at the top of `SalesOrderService.php`: `use App\Models\Invoice;`. Then add the method inside the class:

```php
public function convertToInvoice(SalesOrder $order): Invoice
{
    if (in_array($order->status, ['invoiced', 'cancelled'], true)) {
        throw new \DomainException('This sales order cannot be invoiced.');
    }
    if ($order->invoice()->exists()) {
        throw new \DomainException('This sales order already has an invoice.');
    }

    return DB::transaction(function () use ($order): Invoice {
        $invoice = Invoice::create([
            'customer_id' => $order->customer_id,
            'sales_order_id' => $order->id,
            'invoice_date' => today(),
            'due_date' => today()->addDays(30),
            'total_amount' => $order->total_amount,
            'payment_status' => 'pending',
        ]);

        foreach ($order->items as $item) {
            $invoice->items()->create([
                'account_id' => $item->account_id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'amount' => $item->amount,
                'tax_amount' => $item->tax_amount,
                'tax_rate_id' => $item->tax_rate_id,
            ]);
        }

        $order->update(['status' => 'invoiced']);

        return $invoice;
    });
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=ConvertToInvoiceTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/SalesOrderService.php tests/Feature/SalesOrder/ConvertToInvoiceTest.php
git -C src commit -m "feat(sales-order): convert SO to invoice"
```

---

### Task 4: Tenancy + Filament SalesOrderResource + convert actions

**Files:**
- Create: `src/app/Filament/App/Resources/SalesOrders/SalesOrderResource.php`
- Create: `src/app/Filament/App/Resources/SalesOrders/Pages/{ListSalesOrders,CreateSalesOrder,EditSalesOrder}.php`
- Modify: `src/app/Filament/App/Resources/Estimates/EstimateResource.php` (add "Convert to Sales Order" action)
- Test: `src/tests/Feature/SalesOrder/SalesOrderTenancyTest.php`

**Interfaces:**
- Consumes: `SalesOrderService` (Tasks 2-3), `SalesOrder` (Task 1).
- Produces: a team-scoped, mostly-read Filament resource with a row action `convertToInvoice` calling `SalesOrderService::convertToInvoice`; an Estimate row action `convertToSalesOrder` (visible only when `status==='accepted'` and no existing sales order) calling `SalesOrderService::createFromEstimate`.

- [ ] **Step 1: Write the failing test (tenancy stamp + action wiring)**

```php
<?php // src/tests/Feature/SalesOrder/SalesOrderTenancyTest.php
declare(strict_types=1);
namespace Tests\Feature\SalesOrder;

use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Team;
use App\Models\User;
use App\Services\SalesOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesOrderTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_converted_sales_order_carries_actor_team(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $customer = Customer::factory()->create();
        $estimate = Estimate::create([
            'customer_id' => $customer->id, 'estimate_number' => 'EST-9',
            'estimate_date' => '2026-06-01', 'subtotal_amount' => 10,
            'tax_amount' => 0, 'total_amount' => 10, 'status' => 'accepted',
        ]);
        EstimateItem::create(['estimate_id' => $estimate->estimate_id, 'description' => 'x',
            'quantity' => 1, 'unit_price' => 10, 'amount' => 10, 'tax_amount' => 0]);

        $so = app(SalesOrderService::class)->createFromEstimate($estimate);

        $this->assertSame($team->id, (int) $so->team_id);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=SalesOrderTenancyTest`
Expected: FAIL (`team_id` null — the `creating` hook only stamps when a team exists; with `actingAs` + `current_team_id` it should stamp, so this passes once the hook from Task 1 is present. If Task 1 is done, this test passes immediately and documents the behavior — keep it).

- [ ] **Step 3: Build the Filament resource**

Read an existing resource first — `src/app/Filament/App/Resources/Estimates/EstimateResource.php` — and mirror its exact Filament v5 API (schema/form, table, `getPages`, `#[\Override]`). Create `SalesOrderResource` (`$model = SalesOrder::class`, tenant-scoped by default via the panel), a table showing `sales_order_number`, `order_date`, `total_amount` (money), `status` (badge), and a row `Action::make('convertToInvoice')->requiresConfirmation()->visible(fn (SalesOrder $r) => ! in_array($r->status, ['invoiced','cancelled'], true) && ! $r->invoice()->exists())->action(fn (SalesOrder $r) => app(\App\Services\SalesOrderService::class)->convertToInvoice($r))`. Wrap the action body in a try/catch that surfaces the `DomainException` message as a Filament danger notification. Create the three Pages (List/Create/Edit) mirroring an existing resource's Pages.

- [ ] **Step 4: Add the Estimate action**

In `EstimateResource.php`, add to `recordActions([...])`:

```php
\Filament\Actions\Action::make('convertToSalesOrder')
    ->label('Convert to Sales Order')
    ->icon('heroicon-o-clipboard-document-check')
    ->requiresConfirmation()
    ->visible(fn (\App\Models\Estimate $record): bool => $record->status === 'accepted' && ! $record->salesOrder()->exists())
    ->action(function (\App\Models\Estimate $record): void {
        try {
            app(\App\Services\SalesOrderService::class)->createFromEstimate($record);
            \Filament\Notifications\Notification::make()->title('Sales order created')->success()->send();
        } catch (\DomainException $e) {
            \Filament\Notifications\Notification::make()->title($e->getMessage())->danger()->send();
        }
    }),
```

- [ ] **Step 5: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=SalesOrder`
Expected: PASS (all SalesOrder tests).

- [ ] **Step 6: Commit**

```bash
git -C src add app/Filament/App/Resources/SalesOrders app/Filament/App/Resources/Estimates/EstimateResource.php tests/Feature/SalesOrder/SalesOrderTenancyTest.php
git -C src commit -m "feat(sales-order): Filament resource + convert actions"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test` — expect all green.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=SalesOrder` — the new migrations + unique indexes must apply on MySQL.
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline if only Filament/Eloquent-`mixed` idiom errors remain.
- Pint the new files.
- Adversarial review focus: guard bypass (converting a non-accepted or already-converted estimate via the service directly), tenancy leak (SO/invoice team_id), double-invoice race, item-copy correctness (amounts/tax carried verbatim).

## Self-Review

- **Spec coverage:** SalesOrder + item models ✓ (T1); estimate→SO convert + accepted-only + once guards ✓ (T2); SO→invoice + once + cancelled guard ✓ (T3); links `estimate_id`/`sales_order_id` + unique indexes ✓ (T1 migrations); statuses draft/confirmed/invoiced/cancelled ✓ (T1 model + confirm/cancel); Filament resource + both convert actions ✓ (T4); tenancy stamp ✓ (T4 test + T1 hook); direct estimate→invoice path untouched ✓ (not modified). Deferred items (fulfilment/shipment/inventory) intentionally absent.
- **Placeholders:** none — every step has real code/commands. (T4 Steps 3 says "mirror EstimateResource" for the resource skeleton because the Filament v5 boilerplate must match the in-repo version exactly; the behavioral parts — table columns, both actions, guards — are given verbatim.)
- **Type consistency:** `createFromEstimate(Estimate): SalesOrder` and `convertToInvoice(SalesOrder): Invoice` used identically across T2/T3/T4; `salesOrder()` relation name consistent on Estimate (`hasOne`) and Invoice (`belongsTo`); PK `id` on SalesOrder used in `invoice.sales_order_id` FK and assertions.
