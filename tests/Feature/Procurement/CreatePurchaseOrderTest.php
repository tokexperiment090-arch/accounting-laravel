<?php // src/tests/Feature/Procurement/CreatePurchaseOrderTest.php
declare(strict_types=1);
namespace Tests\Feature\Procurement;

use App\Models\PaymentTerm;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\ProcurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    private function approvedRequest(): PurchaseRequest
    {
        // purchase_orders.team_id has a real FK to teams — use a real team so the
        // converted PO inserts under FK enforcement (never disable FKs to pass).
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->teamId = $team->id;

        $term = PaymentTerm::create([
            'payment_term_name' => 'Net 30',
            'payment_term_description' => 'Net 30 days',
            'payment_term_number_of_days' => 30,
        ]);
        $supplier = Supplier::create([
            'payment_term_id' => $term->payment_term_id,
            'supplier_first_name' => 'Test',
            'supplier_last_name' => 'Supplier',
            'supplier_email' => 'supplier@test.com',
            'supplier_address' => '123 Main St',
            'supplier_phone_number' => '123-456-7890',
            'supplier_limit_credit' => 10000,
            'supplier_tin' => 123456789,
        ]);
        $request = PurchaseRequest::create([
            'supplier_id' => $supplier->supplier_id,
            'request_date' => '2026-07-01', 'total_amount' => 300,
            'status' => 'draft', 'approval_status' => 'approved', 'team_id' => $team->id,
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
        $this->assertSame($this->teamId, (int) $po->team_id);
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
