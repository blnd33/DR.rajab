# OrthoTrack — Architecture Proposal (Phase 0 gate)

> Status: **Approved 2026-10-25 (D1–D10). Phase 0 built.**
> Companion document: [DATABASE.md](DATABASE.md) (full schema for all phases).
>
> **Changes made while building Phase 0** (see CHANGELOG):
> * D2: no translatable plugin at all — admin forms show EN / AR / KU inputs side by side (`App\Filament\Admin\Forms\TranslatableInput`). The plugin's locale switcher hides languages and its own README warns about complex fields.
> * `bezhansalleh/filament-shield` dropped: a custom role editor (`RoleResource` + `PermissionChecklist`) shows the permission registry grouped by module with translated labels; the same component powers per-user permissions. Spatie permission is unchanged.
> * `laravel/sanctum` not needed: sync endpoints use the web session + CSRF.
> * Usernames/emails: case-insensitive uniqueness via `lower()` unique indexes (no `citext`).

---

## 0. Decisions that need your approval

These are places where the brief and today's package ecosystem disagree, or where I made an architectural call. Everything else follows the brief literally.

| # | Topic | Brief says | Proposal | Why |
|---|-------|-----------|----------|-----|
| D1 | Filament / Livewire version | "Filament latest stable v3+", "Livewire 3" | **Filament 5.x + Livewire 4.x** on **Laravel 13** | Latest stable today: Laravel 13.38, Filament 5.10, Livewire 4.4. Filament 5 *requires* Livewire 4, so the two panels must share one Livewire version. Livewire 4 keeps the v3 component API (`wire:model`, `#[Computed]`, Alpine bridge), so nothing in the brief is affected. Staying on Filament 3 would mean starting on an end-of-life line. |
| D2 | Filament translatable plugin | `filament/spatie-laravel-translatable-plugin` | **`lara-zeus/spatie-translatable` ^2.0** | The official plugin stopped at v3.3 and does not support Filament 4/5. The Lara Zeus fork is its maintained successor (same API: locale switcher + `Translatable` concern), and 2.x targets Filament 5. |
| D3 | Two-factor auth | `pragmarx/google2fa-laravel` *or* Filament MFA | **Filament built-in MFA** (TOTP app + recovery codes) | It's native to the panel login and needs no extra dependency. We enforce it for Super Admin, and it's optional for others. The Ops app uses the same login, so MFA covers both panels. |
| D4 | Barcode generation | `picqer/php-barcode-generator` + a DataMatrix/QR lib | **`tecnickcom/tc-lib-barcode`** (one library) | It generates Code128, GS1-128, **DataMatrix (incl. GS1)**, QR and EAN as SVG/PNG. One maintained library instead of two. |
| D5 | Branch scope for permissions | "what + where" | **spatie (what) + our own `user_permission_branches` table (where)** — *not* spatie "teams" | Details in §4. Spatie teams switch context per request, so "show me all transfers I may see across branches" turns into N queries. Teams also complicates Shield. Our resolver returns a list of branch IDs per ability, and that list feeds both policies and query scopes. |
| D6 | Attachments | not specified | **Own polymorphic `attachments` table** (no medialibrary) | Attachments are only invoices, receipts and photos. A 10-column table is simpler than adding `spatie/laravel-medialibrary`. |
| D7 | Excel import tables | maatwebsite/excel | Custom `data_imports` / `data_import_rows` (preview + old→new diff). We still use Filament's **export** action for simple list exports | Filament's built-in importer can't do the required preview with old→new diffs, and its tables are called `imports`, so ours are named differently to avoid a clash. |
| D8 | Extra small packages | — | `laravel-lang/common` (dev; Arabic + Kurdish validation strings), `spatie/laravel-health` (Coda health page) | Both are optional. Without them we hand-write the translations and the health checks. |
| D9 | Local dev environment | "NOT XAMPP" | See §9 — **needs your choice** | This Windows machine has PHP 8.5 + Composer + Node 24, but **no PostgreSQL, Redis, Docker or WSL**. Horizon can't run on native Windows (it needs `pcntl`). |
| D10 | PHP target | 8.3+ | Code targets **PHP 8.3**: `composer.json` sets `config.platform.php = 8.3`, so it runs on Ubuntu 24.04's default PHP | Your local PHP is 8.5. Setting the platform stops Composer from picking packages that need a newer PHP. |

---

## 1. High-level shape

```
                ┌──────────────── one Laravel 13 app ────────────────┐
  Browser/PWA → │  /login (single)  → redirect by role/permission     │
                │  /admin  Filament 5 panel  (Super Admin, Accountant)│
                │  /app    Livewire 4 Ops app (Doctor, Cashier, Staff)│
                │      │                    │                         │
                │      └──── both call ─────┘                         │
                │      app/Services/*  (all business rules)           │
                │      Policies + BranchScope (shared authorization)  │
                │      Ledgers: stock_movements, money_transactions   │
                └───────────┬────────────────────────┬───────────────┘
                     PostgreSQL 16              Redis (cache, sessions,
                                                 queues → Horizon)
```

Ground rules (enforced in code review and by tests):

1. **Filament resources and Livewire components never write stock or money directly.** They call a Service method. Each Service method opens a DB transaction, uses `lockForUpdate` on the rows it changes, writes the ledger row(s), updates the cached state, logs the activity, and fires events.
2. **Ledgers are append-only.** A PostgreSQL trigger rejects `UPDATE`/`DELETE` on `stock_movements`, `money_transactions`, `party_ledger_entries` and `activity_log`. A correction is always a *new* reversing row.
3. **State = f(ledger).** Every cached column (unit status, batch quantity, box balance) can be rebuilt with `php artisan orthotrack:rebuild-state --verify`. That command also runs nightly in verify-only mode and alerts on drift.
4. **Every financial row stores `currency` + `fx_snapshot_id`** (and its deal rate where one applies), so historical reports never move when rates change.

---

## 2. Folder structure

```
orthotrack/
├── app/
│   ├── Enums/                         # one backed enum per status/type (UnitStatus, TrackingType, TransferStatus, …)
│   │                                  #   each implements HasLabel/HasColor/HasIcon (used by both panels)
│   ├── Models/                        # flat (Filament/Shield generators expect it). Thin: relations, casts, scopes
│   │   ├── Concerns/                  # BelongsToBranch, HasDocumentNumber, HasAttachments, Voidable, HasFxSnapshot
│   │   └── Scopes/                    # VisibleToUserScope (branch scope), ActiveScope
│   ├── Services/                      # ALL business logic, grouped by domain
│   │   ├── Auth/                      # LoginRedirector, SessionRegistry, LoginAttemptLogger
│   │   ├── Permissions/               # BranchScopeResolver, PermissionRegistry (single list of abilities)
│   │   ├── Branches/                  # BranchService (open/close rules, close-checklist), BranchStockEvacuator
│   │   ├── Catalog/                   # ProductService
│   │   ├── Stock/                     # StockMovementService (the only writer of stock), FefoPicker, StockQuery
│   │   ├── Barcode/                   # ScanResolver (raw → product/unit/kit/instrument)
│   │   │   └── Parsers/               # Gs1Parser, PlainBarcodeParser, SkuParser, InternalLabelParser (+ future vendor parsers)
│   │   ├── Labels/                    # LabelRenderer (tc-lib-barcode → SVG → thermal-size PDF/HTML)
│   │   ├── Expiry/                    # ExpiryLevelService (validation: no gaps/overlaps), ExpiryClassifier, ExpiryTransitionNotifier
│   │   ├── Transfers/                 # TransferService
│   │   ├── Requests/                  # StockRequestService, SourceBranchSuggester
│   │   ├── Returns/                   # CustomerReturnService, SupplierReturnService
│   │   ├── WriteOffs/                 # WriteOffService
│   │   ├── Counts/                    # StockCountService
│   │   ├── Clinical/                  # SurgeryService, PatientService
│   │   ├── Kits/                      # KitPreparationService, KitCheckoutService, KitReconciliationService, KitRefillPlanner
│   │   ├── Money/                     # MoneyLedgerService (only writer of money), CashClosingService, CurrencyExchangeService
│   │   ├── Fx/                        # FxSnapshotService, RateFetchers/ (EcbFetcher, …), Converter
│   │   ├── Purchasing/                # PurchaseService, ReceivingService, LandedCostAllocator, SupplierPaymentService
│   │   ├── Sales/                     # SalesInvoiceService, CustomerPaymentService
│   │   ├── Expenses/                  # ExpenseService, RecurringExpenseGenerator, ExpenseAllocator
│   │   ├── Approvals/                 # ApprovalService (thresholds from settings; one queue for the dashboard)
│   │   ├── Commissions/               # CommissionCalculator (feature-flagged)
│   │   ├── Imports/                   # ImportPipeline, Validators/, RowNormalizer, DiffBuilder
│   │   ├── Numbering/                 # DocumentNumberGenerator (TRF-DHK-2026-00042, gap-free, locked)
│   │   ├── Pdf/                       # PdfRenderer (Browsershot), DocumentTemplates
│   │   └── Settings/                  # SettingsRepository + typed settings DTOs
│   ├── Support/                       # framework-agnostic helpers
│   │   ├── Locale/                    # LocaleRegistry (reads config/locales.php), DigitConverter, Direction
│   │   ├── Money/                     # Money value object (brick/math decimal — never float), Currency
│   │   └── Gs1/                       # AI table (application identifiers), FNC1/GS handling
│   ├── Data/                          # DTOs passed into services (ScanResult, TransferLine, SurgeryDraft, …)
│   ├── Events/  Listeners/            # domain events → notifications, activity, cache busting
│   ├── Exceptions/Domain/             # ExpiredUnitException, SerialAlreadyUsedException, BranchClosureBlocked, …
│   ├── Filament/
│   │   └── Admin/                     # panel id "admin" → /admin
│   │       ├── Resources/             # one folder per resource (Branches, Users, Products, …)
│   │       ├── Pages/                 # Settings, ExpiryLevels, ImportWizard, Reports/*
│   │       ├── Widgets/               # dashboard cards/charts/approval queue
│   │       └── Clusters/              # Settings cluster, Catalog cluster, Finance cluster
│   ├── Livewire/
│   │   └── Ops/                       # /app screens (thin): Home, Scan, Surgery/*, Transfers/*, Kits/*, Counts/*, Cashier/*
│   ├── Http/
│   │   ├── Controllers/               # Auth, PWA (manifest, offline), PDF/label downloads, sync API
│   │   ├── Controllers/Api/Sync/      # offline-draft sync endpoints (idempotent, client_uuid)
│   │   ├── Middleware/                # SetLocale, NormalizeDigits, TrackUserSession, EnsureActiveUser, EnsurePanelAccess
│   │   └── Requests/                  # FormRequests (shared validation rules used by Livewire too)
│   ├── Policies/                      # one per model; ALL check BranchScopeResolver
│   ├── Notifications/
│   │   └── Channels/                  # NotificationChannelContract; Database, WebPush, Mail; WhatsApp later
│   ├── Jobs/                          # ImportChunkJob, GeneratePdfJob, ExpirySweepJob, LowStockSweepJob, …
│   ├── Imports/  Exports/             # maatwebsite classes (thin; delegate to Services/Imports)
│   ├── Observers/
│   ├── Console/Commands/              # rebuild-state, fetch-rates, generate-recurring-expenses, …
│   └── Providers/
│       └── Filament/AdminPanelProvider.php
├── config/
│   ├── locales.php                    # en/ar/ckb: name, native name, dir, digit system, font stack → add a language here only
│   ├── orthotrack.php                 # feature flags (commissions, credit_sales, price_lists), defaults
│   └── … (permission, activitylog, backup, horizon, webpush, excel)
├── database/
│   ├── migrations/                    # prefixed by phase: 2026_10_25_000100_p0_*, …_p1_*, …
│   ├── sql/                           # triggers (append-only ledgers), views (v_stock_on_hand), functions
│   ├── seeders/                       # Roles+Permissions, Currencies, ExpiryLevels, DemoBranches, DemoUsers, Demo*(per phase)
│   └── factories/
├── lang/
│   ├── en.json  ar.json  ckb.json     # UI strings
│   └── en/ ar/ ckb/                   # PHP files: validation, enums, filament overrides, pdf
├── resources/
│   ├── css/
│   │   ├── ops.css                    # Tailwind entry for /app
│   │   ├── tokens.css                 # design tokens (CSS variables) shared by both panels
│   │   └── filament/admin/theme.css   # Filament custom theme (imports tokens.css)
│   ├── fonts/                         # self-hosted Inter, Vazirmatn, JetBrains Mono (woff2) — no CDN, works offline
│   ├── js/
│   │   ├── ops/
│   │   │   ├── app.js
│   │   │   ├── scanner/               # Alpine component: ZXing camera + HID keyboard-wedge listener
│   │   │   ├── gs1/                   # JS GS1 parser (offline preview); server parser is authoritative
│   │   │   ├── offline/               # IndexedDB store (idb), sync queue, online/offline banner
│   │   │   ├── digits.js              # normalize ٠-٩ / ۰-۹ → 0-9 on input
│   │   │   └── components/            # toast, confirm-dialog, bottom-sheet (Alpine)
│   │   └── sw.js                      # Workbox service worker (app shell + static assets; no business data)
│   └── views/
│       ├── layouts/ops.blade.php      # mobile-first shell: top bar, bottom nav, offline banner, toasts
│       ├── components/ops/            # x-ops.button, x-ops.status-chip, x-ops.scan-field, x-ops.card-list, …
│       ├── livewire/ops/
│       ├── pdf/                       # invoice, transfer-slip, surgery-report, kit-checklist, stock-report
│       └── labels/                    # thermal label templates (50×25, 40×30, 62×29 mm…)
├── routes/
│   ├── web.php                        # login, redirect, PWA, downloads
│   ├── ops.php                        # /app/* (auth, active, panel:ops)
│   └── api.php                        # /api/sync/* (sanctum session auth, idempotent)
├── tests/
│   ├── Feature/                       # Pest: stock movements, surgery deduction, transfers, kit reconciliation, payments+fx
│   ├── Unit/                          # Gs1Parser, DigitConverter, ExpiryLevel validation, LandedCostAllocator
│   └── fixtures/gs1-vectors.json      # shared by PHP + JS parser tests (real box scans go here when the client sends photos)
├── deploy/
│   ├── deploy.sh                      # git pull → composer --no-dev → migrate --force → npm build → cache → horizon:terminate
│   ├── nginx/orthotrack.conf
│   ├── supervisor/horizon.conf
│   └── cron/scheduler
├── docs/                              # ARCHITECTURE.md, DATABASE.md, RESTORE.md, design/DESIGN-SYSTEM.md
├── README.md
└── CHANGELOG.md
```

---

## 3. Packages

### PHP (composer)

| Package | Purpose | Phase |
|---|---|---|
| `laravel/framework` ^13 | Framework | 0 |
| `filament/filament` ^5 | Admin panel `/admin` (includes Livewire 4, Chart.js widgets, MFA) | 0 |
| `livewire/livewire` ^4 | Ops app `/app` (pulled in by Filament; pinned explicitly) | 0 |
| `bezhansalleh/filament-shield` ^4.3 | Roles/permissions UI in Filament (supports Filament 4/5) | 0 |
| `spatie/laravel-permission` ^6 | Roles & permissions ("what") | 0 |
| `spatie/laravel-translatable` ^6 | JSON translatable columns | 0 |
| `lara-zeus/spatie-translatable` ^2 | Filament locale switcher for translatable fields (replaces official plugin — D2) | 0 |
| `spatie/laravel-activitylog` ^4 | Audit log | 0 |
| `laravel/horizon` ^5 | Redis queue workers + dashboard | 0 |
| `predis/predis` *or* phpredis ext | Redis client (phpredis preferred on the VPS) | 0 |
| `laravel/sanctum` | Session-authenticated JSON endpoints for offline sync | 0 |
| `laravel-notification-channels/webpush` | PWA push | 0 (wired), 2 (events) |
| `brick/math` | Exact decimal arithmetic for money/rates (never float) | 0 |
| `tecnickcom/tc-lib-barcode` | Code128 / GS1-128 / DataMatrix / QR label generation (D4) | 1 |
| `maatwebsite/excel` ^3.1 | Excel import/export (queued chunks) | 1 |
| `spatie/browsershot` | PDFs via headless Chrome (correct Arabic/Kurdish shaping) | 1 (labels), 5 (docs) |
| `spatie/laravel-backup` ^9 | Encrypted off-site backups | 5 (configured in 0) |
| `league/flysystem-aws-s3-v3` | S3-compatible disk (Backblaze B2) for backups + uploads | 0 |
| `spatie/laravel-health` *(optional, D8)* | Health check page for Coda admin | 5 |
| **dev:** `pestphp/pest` + `pest-plugin-laravel`, `larastan/larastan`, `laravel/pint`, `laravel-lang/common` *(D8)*, `barryvdh/laravel-debugbar` (local only) | | 0 |

### JS (npm)

| Package | Purpose |
|---|---|
| `vite`, `laravel-vite-plugin`, `tailwindcss` ^4, `@tailwindcss/vite` | Build + styling (Tailwind 4 has logical utilities `ms-/me-/ps-/pe-/start-/end-` built in) |
| `@zxing/browser` (+ `@zxing/library`) | Camera scanning: GS1 DataMatrix, QR, Code128, EAN |
| `vite-plugin-pwa` (Workbox under the hood) | Manifest + Workbox service worker generation |
| `idb` | Small IndexedDB wrapper for offline drafts |
| Alpine.js | Comes with Livewire — not installed separately |

---

## 4. Permissions = *what* × *where*

* **What** — spatie permissions, named `domain.action` (e.g. `transfers.send`, `transfers.receive`, `stock.view`, `surgeries.create`, `patients.view_all`, `rates.set_usd_iqd`, `approvals.payments`). There is one registry (`PermissionRegistry`), and Shield's generated permissions are mapped into it so nothing gets defined twice.
* **Roles are templates** (Super Admin, Doctor, Cashier, Accountant, Branch Staff). The Super Admin can grant or remove direct permissions per user (spatie `model_has_permissions`).
* **Where** — branch scope is resolved like this:
  1. If the user has `user_permission_branches` rows for that permission → use them (a `NULL` branch means *all branches*).
  2. Otherwise → the user's default scope: `users.all_branches = true` → all branches; else the branches in `branch_user`.
  3. Super Admin → all branches, always (`Gate::before`).
* `BranchScopeResolver::branchIdsFor($user, 'transfers.send'): array|ALL` feeds:
  * **Policies** — `can('send', $transfer)` checks the ability *and* that `$transfer->from_branch_id` is in scope.
  * **Query scope** — `Transfer::visibleTo($user, 'transfers.view')` adds `whereIn(branch_id, …)` so every list, report and API endpoint is filtered the same way.
  * **Filament** — resources apply the same scope in `getEloquentQuery()`, and branch selects only offer in-scope, open branches.
* Patients: doctors see patients they operated on (via `surgery_doctors`) or are the primary doctor for; `patients.view_all` widens that, still limited by branch scope.

---

## 5. Stock & money ledgers (the core of data safety)

**Stock.** `StockMovementService` is the *only* code that changes `stock_units.status/branch_id/location` or `stock_batches.quantity`. Each call:

```
DB::transaction(function () {
    lock affected stock_units / stock_batches rows (lockForUpdate, ordered by id to avoid deadlocks)
    assert preconditions (status allowed, not expired unless override+reason, branch in scope)
    insert stock_movements row(s) (append-only, grouped by movement_group uuid)
    update cached state on units/batches
    activity()->log(...) ; dispatch events after commit
});
```

A serial can't be used twice: the unit row lock plus a status precondition (`in_stock|in_kit|out_at_hospital` → `used_in_surgery`) mean a second concurrent attempt finds the row already `used_in_surgery` and fails with `SerialAlreadyUsedException`.

**Money.** `MoneyLedgerService` is the only writer of `money_transactions` and `cash_boxes.current_balance`, and it follows the same pattern. Supplier and customer balances live in `party_ledger_entries` (per currency).

**FX.** Rates are append-only in `exchange_rates`. Confirming a rate creates an immutable `fx_snapshots` row (USD/IQD + EUR/USD at that moment). Every financial document stores `fx_snapshot_id`, plus its own `applied_rate` when the deal used a different rate (exchanges, cross-currency payments). Reports in any currency convert through each row's own snapshot, so history never changes.

---

## 6. Languages, RTL, digits

* `config/locales.php` is the single source of truth, so adding a language = one config entry + 3 lang files:
  ```php
  'ckb' => ['name' => 'Kurdish (Sorani)', 'native' => 'کوردی', 'dir' => 'rtl', 'digits' => 'arab', 'font' => 'vazirmatn'],
  ```
* `SetLocale` middleware: user's `locale` → session → `Accept-Language` → `en`. It sets `<html lang dir>` in both panels (Filament reads `dir` from the locale).
* Translatable fallback chain: user locale → `en` → `ar` (set through spatie translatable's fallback config plus a model-level `getFallbackLocales`).
* **Digits (display):** `DigitConverter::forDisplay($value, $locale)` turns digits into `٠١٢٣٤٥٦٧٨٩` (U+0660–0669) for `ar`/`ckb`. It's applied by Blade components (`<x-num>`, `<x-money>`) and Filament `formatStateUsing`. **Codes never pass through it**, and the `<x-code>` component renders them in `dir="ltr"` mono font.
* **Digits (input):** `NormalizeDigits` middleware + the JS `digits.js` both convert Arabic-Indic (U+0660–0669) **and** Persian (U+06F0–06F9) digits, Arabic decimal `٫` and thousands `٬`, to ASCII before validation.
* Dates are always shown as `YYYY-MM-DD` (one `Date::display()` helper; Filament default date format set globally).
* Fonts (self-hosted woff2): **Inter** (en), **Vazirmatn** (ar/ckb — full Sorani coverage: ڕ ڵ ێ ۆ ە ڤ گ چ پ ژ), **JetBrains Mono** for serials/lots/SKUs (clear 0/O and 1/l).
* PDFs: Browsershot renders the same Blade + fonts in Chrome, so Arabic/Kurdish shaping and RTL come out right.

---

## 7. PWA & offline-tolerant pieces

* `vite-plugin-pwa` (Workbox) precaches the app shell, CSS/JS and fonts. Navigation uses network-first with an offline fallback page. **No business data is cached.**
* Install prompt: a custom "Install app" banner (`beforeinstallprompt` on Android/desktop; an iOS "Add to Home Screen" hint sheet, which is also required for iOS push).
* Offline drafts (Alpine + IndexedDB): **surgery draft**, **transfer receive scan list**, **stock count scan list**. Each draft has a `client_uuid`. Sync goes to `/api/sync/*`, and the endpoints are idempotent via that UUID: a retry never double-deducts stock. A permanent top banner shows *Offline · 3 items waiting to sync*.
* Scanner component: ZXing camera with format hints (DataMatrix, QR, Code128, EAN-13/8, GS1). There's also a hidden keyboard-wedge listener, so USB/Bluetooth scanners work in any scan field (fast keystrokes ending in Enter are treated as a scan; GS separator `\x1D` and `]d2`/`]C1` symbology prefixes are handled). It beeps and vibrates on success, and uses a distinct pattern on error.

---

## 8. Design direction (via `/ui-ux-pro-max`)

The skill's `--design-system` search returned a landing-page pattern on its first query (Neumorphism — flagged *accessibility risk: high*). Neither fits an operational tool, so I re-queried for a data-dense healthcare admin tool. The result: **Minimalism / Swiss style** (accessibility risk: low), plus the skill's *Medical Clinic* clinical-teal palette, adjusted so white text passes WCAG AA. I deliberately did **not** persist the raw skill output. The synthesized system goes into `docs/design/DESIGN-SYSTEM.md` in Phase 0.

| Token | Value | Notes |
|---|---|---|
| `--color-primary` | `#0E7490` (cyan-700) | Contrast with white text: 5.4:1 ✓ (the skill's `#0891B2` needed black text, so I darkened it) |
| `--color-primary-strong` | `#155E75` | pressed/hover |
| `--color-bg` / `--color-card` | `#F8FAFC` / `#FFFFFF` | neutral (no tint) for long sessions |
| `--color-fg` / `--color-muted-fg` | `#0F172A` / `#475569` | 15.4:1 / 7.6:1 |
| `--color-border` | `#E2E8F0` | |
| success / warning / danger / info | `#15803D` / `#B45309` / `#B91C1C` / `#1D4ED8` | all ≥ 4.5:1 on white |
| Expiry level colors | **user-configured** | text color (black/white) is computed automatically from luminance, so a yellow chip stays readable |

Ops-app UX rules: base font 16px (17px for ar/ckb, line-height 1.6) · touch targets ≥ 48px · bottom nav ≤ 5 items (Home · Surgery · **Scan** (center) · Tasks · More) · tables become cards under 768px · every status = icon + text label + color · confirmation sheets for irreversible actions · skeleton loaders · `prefers-reduced-motion` respected · Lucide/Heroicons SVG (no emoji icons) · logical-property utilities only (`ms-`/`pe-`/`start-`), with a Pint/stylelint check that fails CI on `ml-`/`pr-`/`left-`.

Filament theme: custom `theme.css` reusing the same tokens (primary = cyan-700 scale), Vazirmatn/Inter font swap per locale, and RTL handled natively by Filament.

---

## 9. Local development environment — **choose one (D9)**

| Option | What you install | Pros | Cons |
|---|---|---|---|
| **A. WSL2 + Ubuntu 24.04** *(recommended)* | `wsl --install`, then PHP 8.3, PostgreSQL 16, Redis, Chrome inside Ubuntu | Same OS as the VPS; Horizon + Browsershot work exactly like production | Needs a one-time WSL setup + reboot |
| B. Docker Desktop + Laravel Sail | Docker Desktop (uses WSL2) | One-command stack; reproducible for the whole Coda team | Heavier on RAM; still needs WSL2 |
| C. Native Windows | PostgreSQL 16 installer + Memurai (Redis for Windows) | No virtualization | Horizon can't run (no `pcntl`), so it falls back to `queue:work`; Browsershot paths differ from production |

---

## 10. Phase 0 — what gets built once approved

**Migrations (Phase 0 subset of DATABASE.md):** `users` (extended), `branches` (base columns — close rules come in Phase 1), `branch_user`, spatie permission tables, `user_permission_branches`, `user_sessions`, `login_attempts`, `activity_log` (+ `branch_id`, `ip_address`, `user_agent`, `reference_code`, append-only trigger), `settings`, `notifications`, `push_subscriptions`, `notification_preferences`, `attachments`, `document_sequences`, `idempotency_keys`, `currencies`, Laravel `jobs`/`failed_jobs`/`job_batches`, Filament `exports`.

**Models:** User, Branch, Setting, Attachment, DocumentSequence, UserSession, LoginAttempt, Currency, + Enums (`Locale`, `BranchStatus`, `DigitSystem`).

**Services:** LoginRedirector, SessionRegistry, LoginAttemptLogger, BranchScopeResolver, PermissionRegistry, LocaleRegistry, DigitConverter, SettingsRepository, DocumentNumberGenerator.

**Routes:**
* `/login`, `/logout`, `/` → role-based redirect (`/admin` for Super Admin/Accountant, `/app` for everyone else; anyone with both accesses gets a panel switcher).
* `/admin/*` — Filament: Dashboard (placeholder widgets), Users (with branch scope + per-permission branch editor + active sessions → "log out this device"), Roles (Shield), Branches (basic CRUD), Settings (company info + logo, digit system per language, session timeout, approval thresholds placeholders), Activity Log (read-only list + filters).
* `/app/*` — Ops: Home (role cards placeholder), Scan (component wired, lookup stub), Notifications, Profile (language switch, my devices).
* `/manifest.webmanifest`, `/sw.js`, `/offline`.

**Seeders:** roles + full permission list (for all phases, so later phases only add screens), 3 currencies, demo branches (Duhok `DHK`, Erbil `EBL`, Baghdad `BGW`), demo users per role (one Arabic-, one Kurdish-, one English-language user), default settings.

**Tests (Pest):** login redirect per role · inactive user blocked · failed-login logging + rate limit · branch-scope resolver (role default, per-permission override, all-branches) · policy + query scope agree · digit normalization (Arabic + Persian digits, decimals) · locale/dir switching · activity log is append-only (UPDATE rejected) · revoked session is logged out.

**Docs:** README (setup, VPS deploy, Horizon/Supervisor, cron, HTTPS, backups), CHANGELOG (Phase 0), `deploy/deploy.sh`, `docs/design/DESIGN-SYSTEM.md`.
