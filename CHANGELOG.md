# Changelog

All notable changes per build phase.

## [Phase 3 — Clinical & assets] — 2026-10-26

Plan: [docs/phases/PHASE-3-PLAN.md](docs/phases/PHASE-3-PLAN.md) (decisions P3-1 … P3-12; built straight after Phase 2).

### Added
- **Patients** (sensitive): file number (generated or typed), name, phone and notes **encrypted at rest**, phone found through a hash (`0750…` and `+964 750…` match), age or date of birth, gender, primary doctor. Doctors see only their own patients (primary doctor or operated) unless they have *View all patients*; every opening of a file is in the activity log; never deleted. Surgery history with the serials used, for follow-ups and recalls.
- **Surgery logging**: type (configurable, translatable list), date, branch, hospital, lead surgeon + assistants, patient (pick or new), side, kit checkout, notes, photos of the implant stickers. *Complete* takes everything out of stock in one transaction (units → used in surgery at that hospital, lots reduced, `surgery_use` rows with cost). Units from another branch or another kit are allowed with a warning on the line (and a notification); expired items need *override expired* + a reason; a serial can be used in one live surgery only. *Cancel* (with reason) puts every item back on the branch shelf (`surgery_reversal`).
- **Offline surgery drafts** (phone): the form lives in the browser — every change is kept in IndexedDB, Save / Complete queues the draft, it is sent to `POST /app/sync/surgeries` now or when the connection is back (from any page). The server updates the same draft for the same `client_uuid`, ignores replays of a completed surgery, reuses a patient with the same phone and name, and keeps anything that blocks (missing hospital, unknown code, used serial, expired without permission) as a draft with the reasons to fix online. *My surgeries* shows drafts still only on the phone.
- **Kits & instruments**: kit types with a template (instrument types + implant sizes, required / optional), physical kits (`KIT-<branch>-0001` code + barcode, home and current branch, status, photo), instruments (`INS-…`, maker serial, holder, maintenance, missing / found, retire). Implants in a kit stay stock of the branch (units *in a kit*, lots in the kit's own bucket). Kit and instrument changes go through `AssetMovementService` into an append-only `asset_movements` ledger (history tab).
- **Kit flow**: prepare / refill by scanning (instrument labels, serials, lot boxes — FEFO) with a live comparison against the template; check out to a hospital (doctor, planned date, optional patient; due back after the *overdue-kit days* setting); surgeries take implants from the kit at the hospital; return by scanning — every line reconciled as *returned*, *used in surgery* (found through the logged surgery) or *unaccounted*; missing / damaged instruments flagged. Unaccounted items open one critical issue and notify the Super Admin, who settles each: found (back in the kit), used (surgery logged now) or lost (written off).
- **Kits on transfers**: an available kit travels with everything in it (kit, instruments, implants change branch on receive); loose instruments too. *Move all stock* (branch closing) includes kits and instruments; closing is blocked while kits, instruments, kits out or draft surgeries remain.
- **Overdue kits**: daily in `stock:alerts` (notification per branch), badges and filters, dashboard count.
- **Hospitals**: detail page with kits lent, surgeries and returns.
- **Dashboard**: *Surgeries and kits* — surgeries today / this month, kits out (overdue), unaccounted implants.
- **Phone app**: Tasks gets *New surgery*, *My surgeries*, *Kits*, surgery drafts and kits out; kit screens (contents vs template, prepare / refill by scan, check out, return by scan); reusable `<x-ops.scan-box>`.
- **Demo data**: `DemoClinicalSeeder` — 4 surgery types, 4 instrument types, knee and hip kit types with templates, 3 kits (one complete, one out at Rizgary and overdue, one only instruments), 5 instruments, 4 patients, a surgery from the shelf, a surgery from the kit, a draft missing its hospital.
- **Tests**: surgeries (complete, drafts with problems, idempotent replay, patient reuse, warnings, expired + override, cancel, permissions, sync endpoint), patients (encryption, phone hash, own vs all, view log), kit flow (template, prepare, checkout → surgery from kit → return → unaccounted → resolve, damaged instruments, kit transfer, overdue reminder, instruments in kits), live screens in 3 languages and privacy between doctors — 213 tests in total.

### Changed from the plan
- Unaccounted items settled as *lost* are written off at once (the Super Admin's decision is the approval) instead of opening a pending write-off.
- `surgeries.problems` (jsonb) and `surgery_items.used_at` added; the serial uniqueness index covers used lines only, so two drafts may hold the same serial until one completes.
- Kit / instrument codes have no year (`KIT-DHK-0001`).
- Printed surgery reports and kit checklists come with Phase 5 (PDFs), as planned.

### Known gaps / to review
- Native date fields on the phone show the device's date format; the chosen date is echoed as YYYY-MM-DD on the surgery form — the other phone date fields get the same treatment in Phase 5.
- Surgery photos are added online (not stored offline).
- Arabic and Sorani texts for Phase 3 were written by the developer — native-speaker review needed before go-live.
## [Phase 2 — Movement] — 2026-10-26

Plan: [docs/phases/PHASE-2-PLAN.md](docs/phases/PHASE-2-PLAN.md) (decisions P2-1 … P2-10; built straight after Phase 1 at the client's request).

### Added
- **Transfers:** draft → add units / lot quantities (scan or pick) → send (units *in transit*, lots leave the shelf; still on the sender's books) → receive by scanning. Missing, short and extra lines are recorded, the transfer becomes *received with differences* and an issue opens; the Super Admin settles each line (*found and received*, *returned to the sender*, *written off as lost*) and the issue closes by itself. Cancel before receiving puts everything back. Expired items need *override expired* + a reason. A unit can only sit on one open transfer or write-off.
- **Stock requests:** a branch asks for products (normal / urgent, needed-by date, preferred source); the approver sets quantity and sending branch per line (suggestion: the branch with the most valid stock); approval creates one draft transfer per sending branch and tells them; the request is *fulfilled* when its transfers arrive. Reject with reason; the requester can cancel while pending.
- **Write-offs:** report damaged / lost / expired / other with photos → approve or reject. Stock moves only on approval; the cost per currency is shown.
- **Returns:** customer returns (units out of our stock or lot quantities) — *good* goes back on the shelf, *damaged* goes back and opens a pending write-off; the unit's origin is kept. Supplier returns: draft → send (units *returned to supplier*, lots off the shelf), one currency per return; the credit note follows in Phase 4.
- **Stock counts:** whole branch / one category / one shelf; snapshot of what should be there; phone scanning with an **offline queue** (IndexedDB, `client_uuid`, `POST /app/sync/count-scans`, replays ignored); lot counts typed in; submit → missing / quantity differs / belongs to another branch / not in the system → decide per line (*adjust* = count adjustment on the ledger, *write off* = pending write-off, *investigating*, *ignore*) → close.
- **Issues inbox** (admin): transfer and count differences in one list, with links to the document, resolve / dismiss with a note.
- **Hospitals & customers** master data (Phase 3 kits / surgeries and Phase 4 receivables use it).
- **Branches:** closing is blocked by open transfers, requests, counts and write-offs; *Move all stock to another branch* sends everything on the shelves in one transfer.
- **Low stock:** *Request from another branch* per row (pre-filled request).
- **Dashboard:** *Waiting for action* — write-offs and requests to approve, transfers in transit, counts to review, open issues.
- **Notifications** (in each user's language): transfer on its way / received / received with differences, request waiting / approved / rejected / transfer to prepare, write-off waiting / decided, count ready for review.
- **Phone app:** bottom bar Home · Stock · **Scan** · Tasks · Me; Tasks hub (to receive, to send, my requests, counts, write-offs waiting) with new transfer, request, count, write-off and customer-return screens.
- **Permissions:** `issues.view_any`, `issues.resolve`; attachments are served through a permission check (`/attachments/{id}`).
- **Demo data:** `DemoMovementSeeder` creates a transfer in transit, one received with a difference, a draft, an urgent request, a write-off, a customer and a supplier return, a running and a submitted count, and three hospitals / clinics — all through the real services.
- **Tests:** transfers (send, receive, differences, settling, cancel, expired, branch close), requests → transfers → fulfilled, write-offs, both returns, counts (snapshot, idempotent sync endpoint, decisions, scope, live lot counts), every new screen in 3 languages and branch refusals — 185 tests in total.

### Fixed (from Phase 1)
- Arabic plurals: Laravel picks six Arabic forms (0, 1, 2, 3–10, 11–99, 100+); the two-form strings showed the singular for 3+ items. All plural strings now have six forms.
- Lots list filter *Only with quantity* did nothing (Filament passes the query by argument **name** — closures must use `$query`).
- Phone: the hint inside code fields uses the normal font (Kurdish / Arabic letters were split by the code font); product sizes no longer break across lines.

### Changed from the plan
- Stock-movement methods are split per item type (`sendUnit` / `sendBatch`, …) instead of the generic names in the plan.
- `issues.title` added (readable summary) next to `details`.
- `transfer_assets` (kits on transfers) moves to Phase 3 with kits (P2-8, as planned).
- `phpunit.xml` raises `memory_limit` to 1G for the full suite.

### Known gaps / to review
- Supplier credit notes, write-off cost in one currency and `fx_snapshot_id` columns arrive with Phase 4.
- Arabic and Sorani texts for Phase 2 were written by the developer — native-speaker review needed before go-live.
- Offline queue covers stock-count scans; offline transfer receiving stays online-only (receive needs the server's verdict per unit).
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
- ~~One-click "Transfer all stock to branch X" and "Request from another branch"~~ — done in Phase 2.
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
