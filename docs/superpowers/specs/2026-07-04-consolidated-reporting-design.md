# Consolidated / Cross-Company Reporting — Design

**Status:** approved (design) · **Date:** 2026-07-04 · **Backlog:** P1-6

## Problem

`FinancialStatementService` produces a P&L, balance sheet, and cash-flow statement for **one** team — every query scopes to `auth()->user()->current_team_id` (via the private `scopedTeamId()`, and team ownership flows through `Account.team_id`, since `journal_entries` has no `team_id`). There is no way to group teams and produce a combined, group-level statement across tenants.

## Decisions (locked)

- **Grouping:** a `ConsolidationGroup` entity; teams attach via a `group_team` pivot (`members()` belongsToMany). A team may belong to several groups.
- **Method:** aggregate member statements **and eliminate intercompany** amounts (so the group isn't double-counted).
- **Statements:** P&L + balance sheet + cash flow.
- **Access:** any member of any member team may **view**; managing a group's membership is limited to members of its `owner_team`.

## Scope boundaries (approved)

- **Eliminations apply to P&L + balance sheet.** **Cash flow consolidates by simple sum** this increment (cash-flow-specific eliminations deferred, documented).
- Ships the elimination **engine + tag column + tests** (entries are tagged directly). A Filament flow to *author* an intercompany entry (mark counterparty on entry creation) is a **follow-up** — out of scope here.
- Single currency assumed (no per-team base currency exists). FX normalization out of scope.

## Architecture

### 1. Grouping (`ConsolidationGroup`)

- Migration `consolidation_groups`: `id`, `name`, `owner_team_id` (FK teams), timestamps.
- Migration `group_team` pivot: `consolidation_group_id`, `team_id`, unique together.
- Model: `members(): BelongsToMany<Team>`, `ownerTeam(): BelongsTo<Team>`.
- Filament `ConsolidationGroupResource` (app panel): create/rename a group, attach/detach member teams (a multi-select of teams the user can see). Membership management gated to `owner_team` members.

### 2. Reparameterize `FinancialStatementService` (targeted, backward-compatible)

Add an immutable team override so consolidation can compute any member without auth context:

```php
private ?int $teamOverride = null;

public function withTeam(int $teamId): self
{
    $clone = clone $this;
    $clone->teamOverride = $teamId;
    return $clone;
}

private function scopedTeamId(): int
{
    return $this->teamOverride ?? auth()->user()?->current_team_id ?? -1;
}
```

Every statement method + helper already resolves team through `scopedTeamId()`, so this one change reparameterizes all of them. Default behavior (no override) is unchanged — existing callers and tests are unaffected.

### 3. Intercompany tag

- Migration: add nullable `counterparty_team_id` (FK teams, `nullOnDelete`) to `journal_entries`. Marks an entry as intercompany with the other party team.
- `JournalEntry`: add to `$fillable` + `counterpartyTeam(): BelongsTo<Team>`.
- (Populating it is the deferred authoring UI; here it's set directly, incl. in tests.)

### 4. `ConsolidationService` (new)

`consolidatedProfitAndLoss(ConsolidationGroup $g, Carbon $start, Carbon $end): array`,
`consolidatedBalanceSheet(ConsolidationGroup $g, Carbon $asOf): array`,
`consolidatedCashFlow(ConsolidationGroup $g, Carbon $start, Carbon $end): array`.

Each:
1. `$members = $g->members` (team ids).
2. For each member: `app(FinancialStatementService::class)->withTeam($id)->{statement}(...)` → per-member result.
3. **Aggregate** line-category totals across members (sum revenue, expense, assets, liabilities, equity, cash sections…).
4. **Eliminate** (P&L + BS only) via `intercompanyTotals($members, …)` and subtract.

Output shape per statement:
```
[ 'members' => [ ['team_id'=>.., 'statement'=>..], .. ],
  'combined' => <line-category totals, summed>,
  'eliminations' => <intercompany amounts removed>,   // zero-filled for cash flow
  'consolidated' => combined − eliminations ]
```

### 5. Eliminations engine

`intercompanyTotals(array $memberIds, Carbon $start, ?Carbon $end, array $accountTypes): array` — sum **posted** `JournalEntryLine`s where:
- the line's `journalEntry.counterparty_team_id` ∈ `$memberIds` (an intercompany entry between members), **and**
- the line's `account.team_id` ∈ `$memberIds`, `account.account_type` ∈ `$accountTypes`,
- within the date window (P&L) or as-of (BS),
summed by each account's normal balance (mirrors `getAccountBalance`).

- **P&L:** eliminate `revenue` and `expense`/`cost_of_goods_sold` categories → intercompany sales net against intercompany purchases.
- **BS:** eliminate the **same asset and liability `account_type` lists `balanceSheet()` uses** (assets: `asset,bank,current_asset,fixed_asset,other_asset`; liabilities: `liability,current_liability,long_term_liability`) → intercompany receivables (assets) net against payables (liabilities). Accounts carry no dedicated receivable/payable type, so intercompany identity comes solely from the entry's `counterparty_team_id` tag, not the account type.

### 6. UI — `ConsolidatedReports` Filament page (app panel)

Pick a group (the user can see) + period → renders the three consolidated statements (combined, eliminations, consolidated columns). View gated: user is a member of some team in the group.

## Data flow

Select group + period → `ConsolidationService` → per-member `FinancialStatementService->withTeam()` statements → line-category aggregation → subtract `intercompanyTotals()` (P&L/BS) → consolidated array → Filament page renders.

## Error handling / edge cases

- Empty group (no members) → zero-filled consolidated statement, no error.
- A member team with no accounts → contributes zeros.
- `counterparty_team_id` pointing outside the group → **not** eliminated (only intra-group counterparties net out) — correct.
- Access: a user not in any member team cannot open the report or the group; membership edits limited to `owner_team`.

## Testing (PHPUnit, sqlite `:memory:`; also under MySQL CI)

1. **Aggregation:** 2-team group, each with revenue/expense/asset accounts + posted entries → consolidated P&L/BS/cash-flow equal the per-team sums.
2. **P&L elimination:** team A posts an intercompany sale to team B (`counterparty_team_id = B`, revenue on A / expense on B). Consolidated revenue and expense **exclude** the intercompany amount; a non-intercompany sale is retained.
3. **BS elimination:** intercompany receivable (A) / payable (B) tagged → netted out of consolidated assets/liabilities.
4. **Cash flow = simple sum:** consolidated cash flow equals the member sum (no elimination applied), `eliminations` section zero.
5. **withTeam isolation:** `FinancialStatementService->withTeam($x)` returns team x's statement regardless of the logged-in user; the default (no override) still uses `current_team_id`.
6. **Access gate:** non-member cannot view; owner-team member can edit membership, a plain member team's user cannot.
7. **Empty group / out-of-group counterparty** edge cases.

## Files

- **New:** `app/Models/ConsolidationGroup.php`, `app/Services/ConsolidationService.php`, `app/Filament/App/Resources/ConsolidationGroups/*` (resource), `app/Filament/App/Pages/ConsolidatedReports.php`, migrations (`consolidation_groups`, `group_team`, `add_counterparty_team_id_to_journal_entries`), `tests/Feature/Consolidation/*`.
- **Changed:** `app/Services/FinancialStatementService.php` (withTeam override), `app/Models/JournalEntry.php` (fillable + relation).

## Non-goals (YAGNI — later, on request)

- Intercompany-entry authoring UI; cash-flow eliminations; multi-currency FX; minority interest / partial ownership; elimination of intercompany equity investments; saved/scheduled consolidated report exports.
