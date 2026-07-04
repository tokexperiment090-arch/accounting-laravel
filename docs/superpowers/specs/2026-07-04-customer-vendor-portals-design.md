# Customer & Vendor Portals — Design

**Status:** approved (design) · **Date:** 2026-07-04 · **Backlog:** P1-7

## Problem

There is no external self-service. `Customer` extends `Authenticatable` with `protected $guard = 'customer'`, but **the guard is not configured**, the `customers` table has **no `password`/`remember_token` column**, and there are no portal routes/panels. `Vendor` is a plain `Model` — not authenticatable at all. Customers and vendors cannot log in to see their own invoices/bills.

## Decisions (locked)

- **Both portals** — customer and vendor — as **Filament panels**, one per auth guard.
- **Read-only, own records only:** invoices (customer) / bills + vendor-credits (vendor), an outstanding-balance dashboard, and document PDF download. No pay-online, profile edit, or statements this increment.
- **Access:** invite + set-password. Staff send a portal invite; the external user sets a password and logs in.

## Scope boundaries / YAGNI

- No online payment (no gateway exists — separate epic). No profile editing, account statements, messaging, or document upload.
- **Password reset uses the same signed-link mechanism as invite** (email a signed set-password link on demand) rather than Laravel's password-broker. Rationale: `Customer`'s email column is `customer_email` (non-standard), which fights the broker's `email`-keyed provider/token flow; a signed link is leaner, needs no reset-token tables, and avoids account-enumeration collisions with the staff `users` table. (Broker-based reset can be added later if needed.)

## Architecture

### 1. Auth foundation

**Migrations**
- `customers`: add `password` (string, nullable), `remember_token`.
- `vendors`: add `password` (string, nullable), `remember_token`, and a **nullable-unique index on `email`** — email is the vendor's auth username, so duplicates would make `retrieveByCredentials` ambiguous (it returns the first match). A vendor needs an email before it can be invited (validated at invite time).

**`config/auth.php`**
- Guards: `customer` `{driver: session, provider: customers}`, `vendor` `{driver: session, provider: vendors}`.
- Providers: `customers` `{eloquent, Customer}`, `vendors` `{eloquent, Vendor}`.
- No new `passwords` brokers (reset is signed-link based).

**Models**
- `Vendor` → extend `Illuminate\Foundation\Auth\User as Authenticatable`, `use Notifiable`, `protected $guard = 'vendor'`, hide `password`/`remember_token`, keep `vendor_id` PK. Auth username = `email`.
- `Customer` — already `Authenticatable`; add `password`/`remember_token` to `$hidden` (present) and implement `FilamentUser`. Auth username = `customer_email`.
- Both implement `Filament\Models\Contracts\FilamentUser::canAccessPanel(Panel $panel): bool` → true **only** for their own portal panel id (`customer` / `vendor`), false otherwise. Staff `User::canAccessPanel` already gates admin/app; ensure it returns false for portal panels.

### 2. Invite / set-password / forgot (shared flow — the security-critical core)

- **`PortalAccessNotification`** (mail) — a signed, expiring URL to the set-password page. One notification, parameterized by guard + route.
- **Staff invite:** a "Send portal invite" Filament action on the existing staff Customer and Vendor resources (`app/Filament/App/Resources/...` / admin) → sends the notification. (Vendor action disabled/validated when the vendor has no email.)
- **Set-password page:** signed routes
  - `GET /portal/set-password/{customer}` (name `portal.customer.set-password`) + vendor equivalent, protected by the `signed` middleware (tamper/expiry enforced by Laravel signed URLs; `URL::temporarySignedRoute(..., now()->addHours(24), [...])`).
  - `POST` sets a hashed password on that record (min-length validated), then redirects to the panel login.
- **Forgot password:** `GET /portal/forgot` (+ vendor) — enter email → if a matching record exists, email the signed set-password link; **always** show the same success message (no account enumeration). Rate-limited (`throttle`).
- A single small `PortalAccessController` handles set-password + forgot for both guards (guard resolved from the route), keeping the flow in one auditable place.

### 3. Filament portal panels (2)

- **`CustomerPanelProvider`** — id `customer`, path `portal`, `authGuard('customer')`, **no tenancy**, `->login(CustomerPortalLogin::class)`, brand. Register in `bootstrap/providers.php`.
- **`VendorPanelProvider`** — id `vendor`, path `vendor-portal`, `authGuard('vendor')`, `->login(VendorPortalLogin::class)`.
- **Custom login pages:** extend Filament's `Login`; override `getCredentialsFromFormData()` to map the form `email` field to the model's column — `['customer_email' => $data['email'], 'password' => ...]` for customer, `['email' => ...]` for vendor. (Needed because the customer's column is `customer_email`.) The login form still shows an "Email" field.

### 4. Read-only, self-scoped resources

- **Customer panel — `PortalInvoiceResource`** (model `Invoice`): `getEloquentQuery()` → `where('customer_id', Auth::guard('customer')->id())`. **Read-only:** `canCreate/canEdit/canDelete = false`; table + a `ViewAction` (infolist) showing number, date, due date, amount, payment status. A **PDF download** action → the existing `Invoice::generatePDF()`, itself re-checking `customer_id === auth id`.
- **Vendor panel — `PortalBillResource`** (model `Bill`, PK `bill_id`): scoped `where('vendor_id', Auth::guard('vendor')->id())`, read-only, view + PDF. Plus a read-only `PortalVendorCreditResource` (or a simple list) scoped to the vendor.
- **Balance dashboard widget** per panel: sum of the auth user's unpaid invoices/bills (a stat card). Scoped to the auth id.

## Security (emphasis — external auth)

- Every portal query is scoped to `Auth::guard(...)->id()`; no unscoped list is ever exposed. Resources are read-only.
- `canAccessPanel` strictly isolates: a customer can reach only `/portal`, a vendor only `/vendor-portal`, staff neither; customers/vendors cannot reach admin/app.
- Invite/reset links are **signed + expiring**; forgot-password does not reveal whether an email exists; login + forgot are throttled.
- PDF/view actions re-verify ownership server-side (defence in depth beyond the scoped query).
- Portal panels set no tenant (the auth identity *is* the scope) — no team leakage.

## Testing (PHPUnit; heavy on isolation)

1. **Cross-record isolation:** customer A logging in sees only A's invoices, never B's (query scope); same for vendor bills.
2. **Panel isolation:** `Customer::canAccessPanel` true for `customer`, false for `vendor`/`admin`/`app`; `Vendor` symmetric; staff `User` false for both portals.
3. **Read-only:** `PortalInvoiceResource::canCreate()/canEdit()/canDelete()` are false.
4. **Invite set-password:** a valid signed URL sets a working password (login succeeds after); a tampered/expired URL is rejected (403).
5. **Forgot enumeration:** unknown email and known email both return the same response; a link is sent only for a real record.
6. **Balance widget:** equals the sum of the auth user's unpaid documents, excludes other users'.
7. **PDF authz:** a customer cannot download another customer's invoice PDF.

## Files

- **New:** `CustomerPanelProvider`, `VendorPanelProvider`, `CustomerPortalLogin`, `VendorPortalLogin`, `PortalAccessController`, `PortalAccessNotification`, `PortalInvoiceResource` (+Pages), `PortalBillResource` (+Pages), `PortalVendorCreditResource` (or list), balance widgets, portal routes, migrations (customers/vendors auth cols), `tests/Feature/Portal/*`.
- **Changed:** `config/auth.php`, `bootstrap/providers.php`, `app/Models/{Customer,Vendor}.php`, the staff Customer/Vendor resources (invite action), `routes/web.php` (portal routes).

## Build order

Auth foundation (migrations, auth.php, Vendor authenticatable, canAccessPanel, invite/set-password/forgot flow, custom login pages) lands first. Then the **customer panel** and **vendor panel** (providers + read-only scoped resources + balance widgets + PDF) parallelize on disjoint files.
