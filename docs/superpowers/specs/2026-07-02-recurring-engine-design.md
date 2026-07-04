# Recurring Invoices, Bills & Expenses — Design

**Status:** approved (design) · **Date:** 2026-07-02 · **Backlog:** P1-2

## Problem

A recurring engine is half-scaffolded and **broken**. `invoices` and `expenses` carry recurrence columns (`is_recurring`, `recurrence_frequency`, `recurrence_start`, `recurrence_end`, `last_generated`); a `recurring:process` command is scheduled `->daily()`; and `Invoice`/`Expense` have `generateRecurring()` methods. But the generation logic has at least five defects, has zero tests, and `bills` have no recurrence at all.

### Current bugs (in `Invoice::generateRecurring()`, mirrored in Expense)
1. **Line items not cloned** — `replicate()` copies attributes, not relations, so every generated invoice is empty.
2. **Exponential recurrence** — `is_recurring = true` is copied onto the child, so each generated invoice itself recurs.
3. **Duplicate number** — `invoice_number` is copied → collides / violates uniqueness.
4. **Mutable-Carbon corruption** — `getNextDate()` calls `->addX()` on the `last_generated`/`recurrence_start` date-cast Carbon (mutable), mutating the model's attribute; it's also called twice per generation.
5. **No catch-up, no idempotency guard** — a missed scheduler day silently drops that period's document; nothing prevents double-generation.

## Goals

- One correct, tested recurrence engine shared by `Invoice`, `Bill`, `Expense`.
- **Catch-up:** generate every occurrence missed since `last_generated` (never skip a billing period), bounded by a safety cap.
- Generated documents are **drafts**: created unpaid, **not** posted to the GL, **not** emailed, **not** auto-approved.
- Add recurrence to `bills` (new columns).
- Correct tenancy: each generated document inherits the template's `team_id`.

## Non-goals (YAGNI — add later only on request)

- Auto-send / auto-post / notifications on generation.
- Frequencies beyond `daily`/`weekly`/`monthly`/`yearly`.
- Proration, mid-cycle changes, per-occurrence overrides, a separate `*_templates` table.
- Backfilling numbering to be per-team (pre-existing global-numbering behavior is out of scope).

## Mental model

A document with `is_recurring = true` is a **template** — configuration, not itself a billable occurrence.

- `recurrence_start` — date of the **first** occurrence to generate.
- `recurrence_end` — nullable; no occurrence is generated with a date after it.
- `last_generated` — date of the **most recently generated** occurrence (null = none yet).
- `recurrence_frequency` — `daily` | `weekly` | `monthly` | `yearly`.

Occurrence dates walk from `recurrence_start` forward by one period each. On each run the engine generates every occurrence whose date is `<= today` (catch-up) that hasn't been generated yet.

## Architecture

### `app/Concerns/Recurring.php` (trait)

Shared engine. Mirrors the `Approvable` concern's style. Public/handler API:

- **`generateDue(): int`** — the catch-up loop:
  ```
  cursor = last_generated ? nextDate(last_generated) : recurrence_start
  count = 0
  while cursor !== null && cursor <= today && (recurrence_end === null || cursor <= recurrence_end):
      if count >= SAFETY_CAP: log warning, break
      makeOccurrence(cursor)          // persists one draft child
      last_generated = cursor; save() // persist per occurrence (see Idempotency): a crash never re-generates
      cursor = nextDate(cursor)
      count++
  return count
  ```
  Guards: returns 0 immediately if `!is_recurring` or `recurrence_start` is null. `SAFETY_CAP = 120` (10 years monthly / ~4 months daily) — hitting it logs the template id and stops, so a misconfigured `recurrence_start` far in the past can't create thousands of rows in one run.

- **`nextDate(Carbon $from): Carbon`** — `match(frequency)` → `copy()->addDay()/addWeek()/addMonth()/addYear()`; `copy()` avoids mutating the source Carbon (fixes bug 4). Unknown frequency → treated as non-recurring (returns null upstream / no-op).

- **`makeOccurrence(Carbon $date): static`** — clones the template into a persisted draft:
  1. `$child = $this->replicate();`
  2. Apply resets: `is_recurring = false`, `last_generated = null`, the number column → `null` (the model's `creating` hook regenerates it), plus `recurringDraftAttributes()` (e.g. `payment_status = 'pending'`, `approval_status = 'pending'`).
  3. Set the document date column(s) to `$date` (and derived due date where applicable).
  4. `$child->save();`
  5. If `recurringItemsRelation()` is non-null, clone each template line item: `replicate()` per item, point its FK at `$child`, save.

### Per-model hooks (tiny, model-specific)

Each model using the trait implements:
- `recurringNumberColumn(): ?string` — e.g. `'invoice_number'`, `'bill_number'`; nulled so the model's `creating` hook regenerates it. Expense has no number column → returns `null` (skip).
- `recurringItemsRelation(): ?string` — `'items'` for Invoice/Bill; `null` for Expense (no line items).
- `recurringDateColumns(): array` — maps the occurrence date onto the model, e.g. Invoice `['invoice_date' => $date, 'due_date' => $date->copy()->addDays(30)]`; Bill similar; Expense `['expense_date' => $date]`.
- `recurringDraftAttributes(): array` — status resets specific to the model.

Models: replace their existing `generateRecurring()` with `use Recurring;` + these hooks. Remove the old broken methods (`generateRecurring`, `shouldGenerateNew`, `getNextDate`) from Invoice and Expense.

### Bills migration

New migration adds to `bills` (mirroring invoices): `is_recurring` (bool, default false), `recurrence_frequency` (string, nullable), `recurrence_start` (date, nullable), `recurrence_end` (date, nullable), `last_generated` (date, nullable). Add the five to `Bill::$fillable` + date casts. (The `SchemaConsistencyTest` guard will confirm fillable↔schema.)

### Command

`app/Console/Commands/ProcessRecurringTransactions.php` (`recurring:process`, already scheduled `->daily()` in `bootstrap/app.php`):
```php
$total = 0;
foreach ([Invoice::class, Bill::class, Expense::class] as $model) {
    $model::where('is_recurring', true)->each(function ($t) use (&$total) { $total += $t->generateDue(); });
}
$this->info("Generated {$total} recurring document(s).");
```
Runs system-wide (no auth context); children inherit `team_id` via `replicate()`, so tenancy is correct without per-team iteration.

## Data flow

Scheduler (daily) → `recurring:process` → for each recurring template → `generateDue()` → catch-up loop → `makeOccurrence()` per missed period → draft child + cloned items persisted, `last_generated` advanced → command logs total.

## Idempotency & safety

- After a run, `cursor > today`, so an immediate re-run generates 0.
- `SAFETY_CAP` bounds a single template's output per run; a capped template resumes next run (its `last_generated` advanced), so no occurrences are lost, just spread across runs.
- All-or-nothing per template not required; each `makeOccurrence` is its own persist. A mid-loop failure leaves `last_generated` at the last success (the `save()` is incremental-safe — advance `last_generated` alongside each occurrence rather than once at the end). **Decision:** advance + persist `last_generated` inside the loop per occurrence so a crash never re-generates already-created children.

## Error handling

- Null/blank `recurrence_frequency` or `recurrence_start` on an `is_recurring` template → `generateDue()` returns 0 (defensive; logged at debug).
- Cloning failure for one template is caught in the command loop so one bad template doesn't halt the rest (log + continue).

## Testing (PHPUnit, sqlite `:memory:`; also runs under the MySQL CI job)

1. **Catch-up:** monthly invoice template, `recurrence_start` 3 months ago, `last_generated` null → 3 drafts; each has cloned line items, a fresh unique `invoice_number`, `is_recurring = false`, `payment_status = pending`, correct ascending `invoice_date`s; template `last_generated` = 3rd date. Re-run → 0 (idempotent).
2. **recurrence_end:** end between occurrence 2 and 3 → only 2 generated.
3. **Bill recurrence:** new columns + `generateDue()` clones bill items.
4. **Expense recurrence:** no items relation → replicate only, correct date, no error.
5. **Safety cap:** `recurrence_start` far in the past → capped at 120, warning logged, `last_generated` advanced, no runaway.
6. **Tenancy:** template in team A → children carry team A's `team_id`.
7. **No side effects:** generated invoice is not posted to GL and fires no notification.

## Files

- New: `app/Concerns/Recurring.php`, bills recurrence migration, `tests/Feature/Recurring/*`.
- Changed: `Invoice.php`, `Bill.php`, `Expense.php` (use trait + hooks, remove old methods), `ProcessRecurringTransactions.php`.
