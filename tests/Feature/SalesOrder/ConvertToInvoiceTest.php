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
