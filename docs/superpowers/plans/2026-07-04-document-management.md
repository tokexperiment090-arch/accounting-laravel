# Document Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A polymorphic document system with per-upload versioning, a retention date + prune, a configurable storage disk (local/S3), a backfill of the legacy `document_path` strings, and a Filament attach/download UI.

**Architecture:** `Document` (morphs to any owner) has many `DocumentVersion` rows (one per upload). A `HasDocuments` trait gives owners a `documents()` relation. `DocumentService` stores files on `config('documents.disk')` and manages versions + prune. Commands `documents:prune` (scheduled) and `documents:backfill` (one-off, idempotent) round it out; a reusable Filament relation manager surfaces it.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`, `Storage::fake`).  Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- `Document` uses `App\Traits\IsTenantModel` + a `creating` hook stamping `team_id` from the owner's `team_id` (fallback `auth()->user()?->currentTeam`).
- Storage disk = `config('documents.disk')` (new `config/documents.php`, default `env('DOCUMENTS_DISK','local')`). Never hardcode a disk name outside that config.
- Tests: sqlite `:memory:` ENFORCES FKs; **never disable FK enforcement / never edit phpunit.xml** — create the required real row instead. `Model::unguard()` is global. No `TeamFactory` — `Team::forceCreate([...])`. Use `Storage::fake(config('documents.disk'))` + `Illuminate\Http\UploadedFile::fake()`.
- Owner PKs are non-standard: `Bill.bill_id`, `Estimate.estimate_id`, `CreditMemo.credit_memo_id`, `Invoice.id`. The polymorphic `documents()` relation stores `documentable_id = $owner->getKey()` — works for all.
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: Document + DocumentVersion models, migrations, HasDocuments trait

**Files:**
- Create: `src/database/migrations/2026_07_08_100001_create_documents_table.php`
- Create: `src/database/migrations/2026_07_08_100002_create_document_versions_table.php`
- Create: `src/app/Models/Document.php`
- Create: `src/app/Models/DocumentVersion.php`
- Create: `src/app/Concerns/HasDocuments.php`
- Modify: `src/app/Models/{Invoice,Bill,Estimate,CreditMemo}.php` (add `use HasDocuments;`)
- Test: `src/tests/Feature/Document/DocumentModelTest.php`

**Interfaces:**
- Produces: `Document` (PK `id`; fillable `documentable_type, documentable_id, name, disk, retention_until, team_id`; `retention_until` cast `date`; `versions()` = `hasMany(DocumentVersion)`, `currentVersion()` = `hasOne(DocumentVersion)->latestOfMany('version_number')`, `documentable()` = morphTo). `DocumentVersion` (fillable `document_id, version_number, path, original_filename, mime_type, size, uploaded_by`). `HasDocuments` trait → `documents(): MorphMany`.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Document/DocumentModelTest.php
declare(strict_types=1);
namespace Tests\Feature\Document;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_belongs_to_an_owner_and_tracks_current_version(): void
    {
        $invoice = Invoice::factory()->create();
        $doc = $invoice->documents()->create(['name' => 'contract.pdf', 'disk' => 'local']);
        DocumentVersion::create(['document_id' => $doc->id, 'version_number' => 1, 'path' => 'documents/a.pdf', 'original_filename' => 'contract.pdf', 'size' => 100]);
        DocumentVersion::create(['document_id' => $doc->id, 'version_number' => 2, 'path' => 'documents/b.pdf', 'original_filename' => 'contract.pdf', 'size' => 120]);

        $this->assertTrue($invoice->documents->contains($doc));
        $this->assertSame(2, $doc->fresh()->currentVersion->version_number);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=DocumentModelTest`
Expected: FAIL (`App\Models\Document` not found).

- [ ] **Step 3: Create the migrations**

```php
<?php // 2026_07_08_100001_create_documents_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('documents', function (Blueprint $t): void {
            $t->id();
            $t->string('documentable_type');
            $t->unsignedBigInteger('documentable_id');
            $t->string('name');
            $t->string('disk')->default('local');
            $t->date('retention_until')->nullable();
            $t->foreignId('team_id')->nullable();
            $t->timestamps();
            $t->index(['documentable_type', 'documentable_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('documents'); }
};
```

```php
<?php // 2026_07_08_100002_create_document_versions_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('document_versions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version_number');
            $t->string('path');
            $t->string('original_filename')->nullable();
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->foreignId('uploaded_by')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('document_versions'); }
};
```

- [ ] **Step 4: Create the models + trait**

```php
<?php // src/app/Models/Document.php
declare(strict_types=1);
namespace App\Models;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = ['documentable_type', 'documentable_id', 'name', 'disk', 'retention_until', 'team_id'];

    #[\Override]
    protected $casts = ['retention_until' => 'date'];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (Document $document): void {
            if (empty($document->team_id)) {
                $document->team_id = $document->documentable?->team_id ?? auth()->user()?->currentTeam?->getKey();
            }
        });
    }

    public function documentable(): MorphTo { return $this->morphTo(); }
    public function versions(): HasMany { return $this->hasMany(DocumentVersion::class); }
    public function currentVersion(): HasOne { return $this->hasOne(DocumentVersion::class)->latestOfMany('version_number'); }
}
```

```php
<?php // src/app/Models/DocumentVersion.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = ['document_id', 'version_number', 'path', 'original_filename', 'mime_type', 'size', 'uploaded_by'];

    #[\Override]
    protected $casts = ['version_number' => 'integer', 'size' => 'integer'];

    public function document(): BelongsTo { return $this->belongsTo(Document::class); }
}
```

```php
<?php // src/app/Concerns/HasDocuments.php
declare(strict_types=1);
namespace App\Concerns;
use App\Models\Document;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasDocuments
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
```

- [ ] **Step 5: Add the trait to the four owners**

In each of `src/app/Models/Invoice.php`, `Bill.php`, `Estimate.php`, `CreditMemo.php`: add `use App\Concerns\HasDocuments;` to the imports and `use HasDocuments;` in the class body (next to the other `use` traits).

- [ ] **Step 6: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=DocumentModelTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git -C src add app/Models/Document.php app/Models/DocumentVersion.php app/Concerns/HasDocuments.php app/Models/Invoice.php app/Models/Bill.php app/Models/Estimate.php app/Models/CreditMemo.php database/migrations/2026_07_08_1000*.php tests/Feature/Document/DocumentModelTest.php
git -C src commit -m "feat(documents): models + versions + HasDocuments"
```

---

### Task 2: DocumentService (attach, addVersion, prune) + config

**Files:**
- Create: `src/config/documents.php`
- Create: `src/app/Services/DocumentService.php`
- Test: `src/tests/Feature/Document/DocumentServiceTest.php`

**Interfaces:**
- Consumes: `Document`, `DocumentVersion`, `HasDocuments` owners (Task 1).
- Produces:
  - `attach(Model $owner, UploadedFile $file, ?Carbon $retentionUntil = null): Document` — stores `$file` on `config('documents.disk')`, creates the `Document` + a v1 `DocumentVersion`; returns the `Document`.
  - `addVersion(Document $document, UploadedFile $file): DocumentVersion` — stores the file, `version_number = max(existing)+1`; returns the version.
  - `prune(): int` — deletes documents whose `retention_until` is before today (and their stored files); returns the count deleted.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Document/DocumentServiceTest.php
declare(strict_types=1);
namespace Tests\Feature\Document;

use App\Models\Document;
use App\Models\Invoice;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('documents.disk'));
    }

    public function test_attach_stores_file_and_creates_version_one(): void
    {
        $invoice = Invoice::factory()->create();
        $doc = app(DocumentService::class)->attach($invoice, UploadedFile::fake()->create('c.pdf', 10));

        $this->assertSame(1, $doc->currentVersion->version_number);
        Storage::disk(config('documents.disk'))->assertExists($doc->currentVersion->path);
    }

    public function test_add_version_increments(): void
    {
        $invoice = Invoice::factory()->create();
        $service = app(DocumentService::class);
        $doc = $service->attach($invoice, UploadedFile::fake()->create('c.pdf', 10));
        $service->addVersion($doc, UploadedFile::fake()->create('c2.pdf', 12));

        $this->assertSame(2, $doc->fresh()->currentVersion->version_number);
        $this->assertCount(2, $doc->fresh()->versions);
    }

    public function test_prune_deletes_expired_documents_and_files(): void
    {
        $invoice = Invoice::factory()->create();
        $service = app(DocumentService::class);
        $doc = $service->attach($invoice, UploadedFile::fake()->create('c.pdf', 10), now()->subDay());
        $path = $doc->currentVersion->path;

        $deleted = $service->prune();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('documents', ['id' => $doc->id]);
        Storage::disk(config('documents.disk'))->assertMissing($path);
    }

    public function test_prune_keeps_documents_without_retention(): void
    {
        $invoice = Invoice::factory()->create();
        app(DocumentService::class)->attach($invoice, UploadedFile::fake()->create('c.pdf', 10));

        $this->assertSame(0, app(DocumentService::class)->prune());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=DocumentServiceTest`
Expected: FAIL (`App\Services\DocumentService` not found).

- [ ] **Step 3: Create the config + service**

```php
<?php // src/config/documents.php
declare(strict_types=1);
return [
    'disk' => env('DOCUMENTS_DISK', 'local'),
];
```

```php
<?php // src/app/Services/DocumentService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    private function disk(): string
    {
        return (string) config('documents.disk', 'local');
    }

    public function attach(Model $owner, UploadedFile $file, ?Carbon $retentionUntil = null): Document
    {
        /** @var Document $document */
        $document = $owner->documents()->create([
            'name' => $file->getClientOriginalName(),
            'disk' => $this->disk(),
            'retention_until' => $retentionUntil,
        ]);

        $this->addVersion($document, $file);

        return $document;
    }

    public function addVersion(Document $document, UploadedFile $file): DocumentVersion
    {
        $teamSegment = $document->team_id ?? 'shared';
        $path = $file->store("documents/{$teamSegment}", $document->disk);

        $next = (int) $document->versions()->max('version_number') + 1;

        return $document->versions()->create([
            'version_number' => $next,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => auth()->id(),
        ]);
    }

    public function prune(): int
    {
        $count = 0;
        $expired = Document::query()
            ->whereNotNull('retention_until')
            ->whereDate('retention_until', '<', today())
            ->with('versions')
            ->get();

        foreach ($expired as $document) {
            foreach ($document->versions as $version) {
                Storage::disk($document->disk)->delete($version->path);
            }
            $document->delete();
            $count++;
        }

        return $count;
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=DocumentServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add config/documents.php app/Services/DocumentService.php tests/Feature/Document/DocumentServiceTest.php
git -C src commit -m "feat(documents): service upload/version/prune"
```

---

### Task 3: prune + backfill commands

**Files:**
- Create: `src/app/Console/Commands/PruneDocuments.php`
- Create: `src/app/Console/Commands/BackfillDocuments.php`
- Modify: `src/bootstrap/app.php` (schedule `documents:prune` daily — add inside the existing `->withSchedule(...)` closure)
- Test: `src/tests/Feature/Document/BackfillDocumentsTest.php`

**Interfaces:**
- Consumes: `DocumentService` (Task 2), `Document`, the four owner models with `document_path`.
- Produces: `documents:prune` (calls `DocumentService::prune()`), `documents:backfill` (for each `Invoice`/`Bill`/`Estimate`/`CreditMemo` with a non-null `document_path` that has no document yet, create a `Document` + a v1 `DocumentVersion` pointing at that path; idempotent).

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=BackfillDocumentsTest`
Expected: FAIL (command `documents:backfill` not defined).

- [ ] **Step 3: Create the commands**

```php
<?php // src/app/Console/Commands/PruneDocuments.php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Services\DocumentService;
use Illuminate\Console\Command;

class PruneDocuments extends Command
{
    #[\Override]
    protected $signature = 'documents:prune';
    #[\Override]
    protected $description = 'Delete documents past their retention date and their stored files';

    public function handle(DocumentService $service): void
    {
        $this->info("Pruned {$service->prune()} document(s).");
    }
}
```

```php
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
```

- [ ] **Step 4: Schedule prune**

In `src/bootstrap/app.php`, inside the existing `->withSchedule(function (Schedule $schedule): void { ... })` closure, add a line:

```php
        $schedule->command('documents:prune')->daily();
```

- [ ] **Step 5: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=BackfillDocumentsTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git -C src add app/Console/Commands/PruneDocuments.php app/Console/Commands/BackfillDocuments.php bootstrap/app.php tests/Feature/Document/BackfillDocumentsTest.php
git -C src commit -m "feat(documents): prune + backfill commands"
```

---

### Task 4: Filament DocumentsRelationManager (Invoice + Bill)

**Files:**
- Create: `src/app/Filament/App/Resources/Invoices/RelationManagers/DocumentsRelationManager.php`
- Modify: `src/app/Filament/App/Resources/Invoices/InvoiceResource.php` (register the relation manager in `getRelations()`)
- Modify: `src/app/Filament/App/Resources/Bills/BillResource.php` (register the same relation manager)
- Test: `src/tests/Feature/Document/DocumentTenancyTest.php`

**Interfaces:**
- Consumes: `DocumentService` (Task 2), `Document`, `HasDocuments` (Task 1).
- Produces: a `DocumentsRelationManager` (relationship `documents`) with a table of `name` + current version number + `retention_until`, an "Upload new version" action (`FileUpload` → `DocumentService::attach`/`addVersion`), and a download action returning the current version's file from its disk. Registered on Invoice + Bill resources.

- [ ] **Step 1: Write the failing test (tenancy stamp)**

```php
<?php // src/tests/Feature/Document/DocumentTenancyTest.php
declare(strict_types=1);
namespace Tests\Feature\Document;

use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_inherits_owner_team(): void
    {
        Storage::fake(config('documents.disk'));
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id]);

        $doc = app(DocumentService::class)->attach($invoice, UploadedFile::fake()->create('c.pdf', 10));

        $this->assertSame($team->id, (int) $doc->team_id);
    }
}
```

- [ ] **Step 2: Run it, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=DocumentTenancyTest`
Expected: PASS (Task 1's `creating` hook copies the owner's `team_id`).

- [ ] **Step 3: Build the relation manager**

Read an existing Filament v5 relation manager in the repo first (search `grep -rl "extends RelationManager" src/app/Filament`) to match the exact API (`Filament\Resources\RelationManagers\RelationManager`, `form`/`table`, `Filament\Forms\Components\FileUpload`, `#[\Override]`). Create `DocumentsRelationManager` with `protected static string $relationship = 'documents';`. Table columns: `name`, `currentVersion.version_number` (label "Version"), `retention_until` (date). Header action "Attach" and a per-row "Upload new version" action, both with a `FileUpload::make('file')` then in the action body call `app(\App\Services\DocumentService::class)->attach($this->getOwnerRecord(), $data['file-as-UploadedFile'], …)` / `->addVersion($record, …)` — resolve the uploaded file to an `UploadedFile` via the temporary upload path, or (simpler + robust) store via `FileUpload` and pass the resulting path to a thin `DocumentService` overload; keep the service call the single source of truth. A row "Download" action returns `Storage::disk($record->disk)->download($record->currentVersion->path, $record->name)`. If wiring `FileUpload` → `UploadedFile` inside a Filament action proves fiddly in this version, it is acceptable to have the action persist the upload through `FileUpload`'s own storage and then record a `DocumentVersion` for that stored path via `DocumentService::addVersion`-equivalent — the behavioral requirement is: one upload = one new version on the correct disk.

- [ ] **Step 4: Register on Invoice + Bill**

In `InvoiceResource::getRelations()` and `BillResource::getRelations()`, return an array including `DocumentsRelationManager::class` (import it). If either `getRelations()` currently returns `[]`, replace with `[\App\Filament\App\Resources\Invoices\RelationManagers\DocumentsRelationManager::class]`.

- [ ] **Step 5: Run all document tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=Document`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add app/Filament/App/Resources/Invoices/RelationManagers app/Filament/App/Resources/Invoices/InvoiceResource.php app/Filament/App/Resources/Bills/BillResource.php tests/Feature/Document/DocumentTenancyTest.php
git -C src commit -m "feat(documents): Filament relation manager"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=Document`.
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline if only Filament/Eloquent-`mixed` idiom errors remain.
- Pint the new files.
- Adversarial review focus: cross-owner/cross-tenant document access (a document scoped to its owner + team), version-number monotonicity under concurrency, `prune` deleting the right files (and not others), backfill idempotency + not double-registering, the FileUpload→version path, and that **no test disabled FK enforcement or edited phpunit.xml**.

## Self-Review

- **Spec coverage:** polymorphic Document + versions ✓ (T1); HasDocuments trait on 4 owners ✓ (T1); DocumentService upload/version ✓ (T2); retention_until + prune ✓ (T2) + scheduled command ✓ (T3); configurable disk ✓ (T2 config); backfill of legacy document_path ✓ (T3); Filament relation manager ✓ (T4); tenancy ✓ (T4 test + T1 hook). Deferred (AV/OCR/named policies) intentionally absent.
- **Placeholders:** none in T1-T3 (full code). T4 Step 3 gives the behavioral contract verbatim (one upload = one version on the right disk, download from disk) and points to an in-repo relation manager to mirror for the Filament-v5 boilerplate + the FileUpload→UploadedFile detail, which must match the installed version rather than be guessed.
- **Type consistency:** `attach(Model, UploadedFile, ?Carbon): Document`, `addVersion(Document, UploadedFile): DocumentVersion`, `prune(): int` identical across T2/T3/T4; `documents()` / `versions()` / `currentVersion()` relation names + `version_number`/`path`/`retention_until` columns consistent T1↔T2↔T3↔T4.
