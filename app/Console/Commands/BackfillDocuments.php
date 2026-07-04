<?php // src/app/Console/Commands/BackfillDocuments.php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\Bill;
use App\Models\CreditMemo;
use App\Models\Document;
use App\Models\Estimate;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class BackfillDocuments extends Command
{
    #[\Override]
    protected $signature = 'documents:backfill';
    #[\Override]
    protected $description = 'Register legacy document_path strings as version-1 documents';

    public function handle(): void
    {
        $count = 0;
        foreach ([Invoice::class, Bill::class, Estimate::class, CreditMemo::class] as $modelClass) {
            $modelClass::query()->whereNotNull('document_path')->each(function (Model $owner) use (&$count): void {
                if ($owner->documents()->exists()) {
                    return; // already backfilled
                }
                /** @var Document $document */
                $document = $owner->documents()->create([
                    'name' => basename((string) $owner->document_path),
                    'disk' => (string) config('documents.disk', 'local'),
                ]);
                $document->versions()->create([
                    'version_number' => 1,
                    'path' => (string) $owner->document_path,
                    'original_filename' => basename((string) $owner->document_path),
                ]);
                $count++;
            });
        }
        $this->info("Backfilled {$count} document(s).");
    }
}
