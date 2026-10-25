# Changelog

All notable changes per build phase.

## [Phase 1 — Core Inventory] — 2026-10-26

Plan: [docs/phases/PHASE-1-PLAN.md](docs/phases/PHASE-1-PLAN.md) (approved P1-1 … P1-6).

### Added
- **Catalog:** products (SKU, GTIN, names in EN/AR/KU, category tree, brand, size, unit, serial or lot tracking, implant flag, prices + currency, default minimum, photo), extra barcodes per product, minimum / maximum / reorder quantity per branch, categories, brands, units, suppliers (master data; payables in Phase 4). Tracking type locks once a product has stock.
- **Stock:** serial units (one row per implant, globally unique serial) and lot buckets (quantity per product × branch × lot × expiry × shelf × cost). Shelves per branch. Statuses and locations from the brief.
- **Stock ledger:** `StockMovementService` is the only writer — permission + branch check, `DB::transaction` + `lockForUpdate`, one `stock_movements` row per change, append-only (database trigger + model guard). Phase 1 movements: opening stock, Excel import, corrections (lot / expiry / cost / supplier / shelf, with reason and old → new on the row), Super Admin status correction. Concurrent receipts of the same serial or bucket are handled (unique index + savepoint retry).
- **Expired items:** recorded with a warning, blocked from use (`assertUsable`) unless the user has *override expired* in that branch and gives a reason — ready for surgeries, sales and transfers.
- **Receive stock** (admin and phone): scan box after box — GS1 fills product, lot, expiry and serial; scanning a lot again adds 1; product defaults fill the cost.
- **Barcodes:** parser chain in `config/orthotrack.php` (`scan_parsers`): our own labels → GS1 (DataMatrix / GS1-128, `]d2` `]C1` `]Q3` prefixes, GS / `<GS>` separators, bracketed form, AIs 01 10 11 17 21 + extras, YYMMDD century rule, day 00, check digit) → plain codes (serial, SKU, GTIN-8/12/13/14, extra barcodes, lot numbers). Unknown codes are saved with their bytes (hex) under *System → Unrecognised scans*.
- **Labels:** templates (size in mm, Code 128 / DataMatrix / GS1 DataMatrix / QR, printed fields, text size); print sheet sized to the label for thermal printers (one label per page), from products, units and lots (single or bulk, copies).
- **Expiry levels:** editable list with color picker, labels in 3 languages, live preview; validation (exactly one "Expired" fixed at ≤ 0 and blocking, no gaps, no overlaps, open-ended last level); readable text color computed per color; optional "blocks usage" and "notify on enter" per level. Expiry chips (color + label + days left + date) everywhere.
- **FEFO:** soonest valid expiry first for units and lots (skips expired and blocking levels); lot quantities allocated across buckets.
- **Serial history & recall:** unit timeline from the ledger (admin and phone); recall search by lot (exact, then partial) across all branches with status and location.
- **Low stock:** `v_stock_on_hand` / `v_low_stock` views, admin page with inline minimum edit and navigation badge, phone filter, dashboard card.
- **Alerts:** daily `stock:alerts` (06:00 Baghdad): items entering a new expiry level and low stock → one digest per branch, in-app + web push, in each user's language.
- **Excel import / export:** templates (Data + Instructions sheet in English, Arabic, Kurdish) for products, stock and minimum stock; upload → check every row → preview with counts and old → new per field → confirm → queued import with progress → error file; original file kept; every step in the activity log. Rules from the brief: matched by SKU / serial, empty cells never overwrite, no branch or status changes, used / sold units rejected, serial = quantity 1, Excel serial dates and text dates, Arabic-Indic digits normalised, Arabic / Kurdish text intact. Exports in the same column format.
- **Dashboard:** stock value per currency (true cost), below minimum, expired, next level, expiry × branch table in the configured colors.
- **Phone app:** bottom bar Home · Stock · **Scan** · Alerts · Me; Scan resolves every code (unit / product / lot / unknown) with FEFO hint, wrong-branch and expired warnings, success / error beep from the server's answer; Stock list with expiry-level and below-minimum filters; product, unit (history), recall and receive screens; Home tiles for the branch.
- **Branches:** shelves tab; closing is blocked while the branch holds stock.
- **Permissions:** new `stock.receive`, `stock.correct`, `labels.templates`; Branch Staff can receive and recall in their branches, Doctors see products and recall, Accountants can correct costs.
- **Seeders:** units, default expiry levels and two label templates (also in production); demo catalog (14 fictional orthopedic products, 3 suppliers, shelves) with stock in DHK / EBL / BGW across every expiry level.
- **Tests:** GS1 vectors (`tests/fixtures/gs1-vectors.json`, shared for future real box scans), ledger rules and concurrency guards, corrections, overrides, append-only, expiry level validation, FEFO, transition notifications, scan resolver, recall, stock value, low stock, branch closing, every Excel rule, template, screens in 3 languages and permission refusals — 157 tests in total.

### Changed from the approved plan
- Lot buckets also key on unit cost + currency (so two deliveries of one lot at different prices keep their true cost); Phase 4 rebuilds the key with the purchase line.
- `stock_movements.details` (jsonb) added for old → new values of corrections.
- Serial, SKU and GTIN matching live in one `PlainCodeParser` instead of separate parsers (same order of checks).
- The daily jobs run as one scheduled command (`stock:alerts`) instead of two queued jobs.
- Excel lot rows: a lot already in stock must have the same quantity (refused otherwise) — Excel never adds to or recounts existing stock; new deliveries go through *Receive stock*.
- Local preview `.env` runs queued jobs immediately (`QUEUE_CONNECTION=sync`); servers keep the queue + worker.

### Known gaps / to review
- One-click "Transfer all stock to branch X" (closing) and "Request from another branch" (low stock) arrive with transfers and stock requests in Phase 2 (P1-4).
- Stock value in one currency arrives with exchange rates in Phase 4 (P1-3).
- Arabic and Sorani texts for Phase 1 were written by the developer — native-speaker review needed before go-live.
- Real box labels from the client have not been scanned yet; add them to `tests/fixtures/gs1-vectors.json` when they arrive.

## [Phase 0 — Foundation] — 2026-10-25

### Added
- **Project:** Laravel 13, PostgreSQL, Redis/Horizon, Filament 5 admin panel (`/admin`), Livewire 4 operations app (`/app`), Vite + Tailwind 4, Pest, Larastan (level 5), Pint.
- **Single login** for both panels (username or email) with role-based redirect: Super Admin/Accountant → `/admin`, Doctor/Cashier/Branch Staff → `/app`; panel switcher for users with both.
- **Permissions = what × where:** full permission registry for all phases (`domain.action`), 5 role templates, per-user extra permissions, per-permission branch scope (`user_permission_branches`), `BranchScopeResolver` used by policies and query scopes. Custom role editor grouped by module, labelled in EN/AR/KU.
- **Users:** create/edit, deactivate/reactivate (never deleted), branches + main branch, language, per-user inactivity timeout; device list with "log out this device" / "log out all devices" (admin and self-service); failed-login logging with reasons; login rate limiting; two-factor (TOTP + recovery codes), required for the Super Admin when the setting is on.
- **Branches (base):** create/edit with EN/AR/KU names, close with reason + blocker checklist (Phase 0 checks users; later phases add stock, transfers, cash, kits), reopen, true delete only without records.
- **Languages:** English, Arabic, Sorani Kurdish; full RTL; per-user language; config-only extension (`config/locales.php`); translatable DB fields with side-by-side language inputs; fallback user locale → en → any.
- **Digits:** Arabic-Indic display for ar/ckb (configurable per language), Western storage; input normalisation of Arabic-Indic + Persian digits for forms and Livewire requests; codes always Western/LTR; dates `YYYY-MM-DD`.
- **Activity log:** spatie activitylog + branch, IP, device and searchable reference code; append-only (DB trigger), read-only viewer with filters and before/after view.
- **Settings:** company + logo, digits per language, security (timeout, login attempts, admin 2FA), approval thresholds, overdue-kit days, exchange-rate sources, optional feature flags.
- **Ops app shell:** mobile-first layout, top bar with branch switcher and notifications, bottom nav, offline/sync banner, toasts, install banner (Android prompt + iOS instructions); screens: Home, Scan (camera via ZXing + USB/Bluetooth scanner + manual entry), Notifications, Me (language, devices, push, logout).
- **PWA:** localised manifest, Workbox service worker (app shell + fonts + scanner precached, network-only for business data, offline page), web push wiring, IndexedDB store for offline drafts (used from Phase 2/3).
- **Infrastructure:** append-only trigger function, gap-free document numbering, attachments table, settings repository, idempotency keys for offline sync.
- **Ops:** deploy script, Nginx (HTTPS, camera permission), Supervisor (Horizon), cron, encrypted off-site backups (30 daily + 12 monthly, failure email to Coda), restore guide.
- **Seeders:** currencies, permissions/roles, default settings; demo branches (DHK, EBL, BGW) and demo users per role/language (non-production only).
- **Tests:** login redirect per role, failed/inactive/no-access login logging, device revoke + deactivation + idle timeout, branch scope resolution and policy/query agreement, append-only audit log, branch close/reopen/delete rules, translatable fallback, document numbers, RTL/locale switching, digit display/normalisation, screen smoke tests, translation completeness.

### Changed from the approved proposal
- `filament/spatie-laravel-translatable-plugin` / `lara-zeus/spatie-translatable` **not used**: all languages are shown side by side in admin forms instead of a locale switcher (clearer for staff; the plugin's own README warns about complex fields).
- `bezhansalleh/filament-shield` **not used**: a custom role/permission editor shows the registry grouped by module with translated labels and supports the branch-scope model.
- `laravel/sanctum` not needed: offline-sync endpoints use the normal session + CSRF.
- `laravel-lang` Sorani validation strings had placeholders machine-translated into numbers (`:attribute` → `١٠`); repaired automatically. Their wording still needs native review.

### Fixed during the first run-through (2026-10-25)
- Cache table migration now runs before the permission tables (spatie clears the cache while migrating).
- Ops app access used outdated permission names — every ops user got 403 on `/app`.
- Activity log crashed (strict mode) for subjects without `branch_id` / `serial` columns.
- Login redirect read the session from the request (failed inside Livewire tests).
- Filament JS/CSS assets are now published automatically (`filament:upgrade` on `composer install`).
- Dates in Arabic-Indic digits showed day-first (`٢٥-١٠-٢٠٢٦`) inside RTL text because of the Unicode bidi algorithm; left-to-right marks keep `YYYY-MM-DD` order.
- Greeting keeps a title with the first name ("Dr. Demo" instead of "Dr."); head-office column shows a star only (no red ✗).
- SQLite preview mode for looking at the app before PostgreSQL is available.

### Known gaps / to review
- Arabic and Sorani UI translations were written by the developer — **native-speaker review needed before go-live** (`lang/ar.json`, `lang/ckb.json`, `lang/ckb/validation.php`).
- Placeholder app icon (teal cross) until the client logo arrives.
