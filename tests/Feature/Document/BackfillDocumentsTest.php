<?php // src/tests/Feature/Document/BackfillDocumentsTest.php
declare(strict_types=1);
namespace Tests\Feature\Document;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_registers_legacy_document_path_as_version_one(): void
    {
        $invoice = Invoice::factory()->create(['document_path' => 'legacy/invoice-42.pdf']);

        $this->artisan('documents:backfill')->assertSuccessful();

        $doc = $invoice->fresh()->documents()->first();
        $this->assertNotNull($doc);
        $this->assertSame('legacy/invoice-42.pdf', $doc->currentVersion->path);
        $this->assertSame(1, $doc->currentVersion->version_number);
    }

    public function test_backfill_is_idempotent(): void
    {
        $invoice = Invoice::factory()->create(['document_path' => 'legacy/invoice-42.pdf']);

        $this->artisan('documents:backfill');
        $this->artisan('documents:backfill');

        $this->assertSame(1, $invoice->fresh()->documents()->count());
    }
}
