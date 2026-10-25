# OrthoTrack — notes for AI assistants and developers

Read `docs/ARCHITECTURE.md` and `docs/DATABASE.md` before changing code. The client brief drives everything; work phase by phase and propose a plan before each phase.

## Hard rules
- Business logic only in `app/Services/*`. Filament resources (`app/Filament/Admin`) and Livewire components (`app/Livewire/Ops`) call services; they never write stock or money directly.
- Every stock / money change: `DB::transaction` + `lockForUpdate` + a ledger row. Ledgers and `activity_log` are append-only (Postgres trigger) — corrections are new reversing rows.
- Authorization = permission ("what", `app/Services/Permissions/PermissionRegistry.php`) **and** branch scope ("where", `BranchScopeResolver`). Add new permissions to the registry; never hardcode role names in checks.
- Nothing financial or stock-related is hard-deleted (void/cancel with a required reason).
- Money: `numeric(18,4)` + `brick/math`; rates `numeric(18,8)`; every financial row stores currency + `fx_snapshot_id`.
- UI strings: `__('English source')` and add the key to `lang/en.json`, `lang/ar.json`, `lang/ckb.json` (`tests/Unit/TranslationsCompleteTest.php` fails otherwise).
- RTL: logical Tailwind utilities only (`ms-/me-/ps-/pe-/start-/end-`). Codes (serial, lot, SKU, barcode, phone, document numbers) render with `<x-code>` / `CodeColumn` — Western digits, LTR.
- Dates display `YYYY-MM-DD`; numbers via `Display::number()` / `<x-num>` (digit system per language).
- Design: `docs/design/DESIGN-SYSTEM.md` (tokens in `resources/css/tokens.css`). Use the `/ui-ux-pro-max` skill for new screens.

## Commands
- `php artisan migrate:fresh --seed` — schema + demo data (non-production)
- `php artisan test` — Pest (feature tests need PostgreSQL database `orthotrack_test`)
- `vendor/bin/phpstan analyse` · `vendor/bin/pint`
- `npm run dev` / `npm run build`
