# Changelog

All notable changes per build phase.

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
