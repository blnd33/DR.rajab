<?php

use App\Services\Barcode\Parsers\Gs1Parser;
use App\Services\Barcode\Parsers\InternalLabelParser;
use App\Services\Barcode\Parsers\PlainCodeParser;
use App\Services\Branches\Checks\ClinicalCheck;
use App\Services\Branches\Checks\DocumentsCheck;
use App\Services\Branches\Checks\MovementCheck;
use App\Services\Branches\Checks\StockCheck;
use App\Services\Branches\Checks\UsersCheck;

return [

    'name' => env('APP_NAME', 'OrthoTrack'),

    'powered_by' => 'Powered by Coda Agency',

    // Business timezone (storage is UTC).
    'timezone' => env('ORTHOTRACK_TIMEZONE', 'Asia/Baghdad'),

    'date_format' => 'Y-m-d',

    'datetime_format' => 'Y-m-d H:i',

    // Email that receives backup failures and health alerts (Coda Agency).
    'ops_admin_email' => env('ORTHOTRACK_OPS_ADMIN_EMAIL', 'ops@coda.agency'),

    'currencies' => ['USD', 'EUR', 'IQD'],

    // A test site for the client (e.g. test.example.com): shows a "Test site" banner on every
    // page, never gets demo data, and is the only kind of site `orthotrack:reset-test-site`
    // will empty. See docs/TEST-SITE.md.
    'test_site' => (bool) env('ORTHOTRACK_TEST_SITE', false),

    // Test site only: open it without signing in. Every visitor is signed in as this user
    // (a username, or "true" for the first active super admin) — no login page, no two-factor.
    // Ignored unless ORTHOTRACK_TEST_SITE=true, so it can never open the real site.
    'auto_login' => env('ORTHOTRACK_AUTO_LOGIN'),

    // The owner's other companies: same system, separate data (App\Support\CompanyContext).
    // KurdishMedicus uses DB_DATABASE. A company is switched on by giving it its own database
    // (same server and login as DB_*); `orthotrack:setup-company` fills it and adds its stations.
    'companies' => [
        'al_fadhaa' => [
            'database' => env('ORTHOTRACK_AL_FADHAA_DATABASE'),
            // code => [English, Arabic, Kurdish, governorate]
            'stations' => [
                'BGD' => ['Baghdad', 'بغداد', 'بەغدا', 'baghdad'],
                'KRB' => ['Karbala', 'كربلاء', 'کەربەلا', 'karbala'],
                'BBL' => ['Babil', 'بابل', 'بابل', 'babil'],
            ],
        ],
    ],

    // Shared hosting without Supervisor / Horizon (docs/HOSTINGER.md): the one-minute cron
    // (schedule:run) also works the database queue — Excel imports, notifications.
    'queue_via_scheduler' => (bool) env('ORTHOTRACK_QUEUE_VIA_SCHEDULER', false),

    // Demo-user seeder password (local/staging only; the seeder refuses to run in production).
    'demo_user_password' => env('DEMO_USER_PASSWORD', 'Ortho-Demo-2026!'),

    /*
    |--------------------------------------------------------------------------
    | Branch dependency checks
    |--------------------------------------------------------------------------
    |
    | Run before closing or deleting a branch. Each phase appends its module
    | (stock, transfers & requests, cash boxes, kits & instruments).
    |
    */
    'branch_checks' => [
        UsersCheck::class,
        DocumentsCheck::class,
        StockCheck::class,
        MovementCheck::class,
        ClinicalCheck::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Barcode parsers
    |--------------------------------------------------------------------------
    |
    | Tried in order; the first that understands a scan wins. To support a new
    | vendor label format, add one ScanParser class above PlainCodeParser.
    |
    */
    'scan_parsers' => [
        InternalLabelParser::class,
        Gs1Parser::class,
        PlainCodeParser::class,
    ],

    // Excel imports: rows applied per queued job.
    'import_chunk_size' => 500,

    // Daily expiry / low-stock check (business timezone).
    'stock_alerts_time' => '06:00',

    // PDF downloads of print pages (Phase 5): headless Chrome via Browsershot. Empty path = auto-detect.
    'pdf' => [
        'enabled' => (bool) env('PDF_ENABLED', true),
        'chrome_path' => env('PDF_CHROME_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default settings
    |--------------------------------------------------------------------------
    |
    | Values used when the `settings` table has no row for a key. Edited in
    | Admin → Settings. Keys are "group.key".
    |
    */
    'settings_defaults' => [
        'company.name' => 'OrthoTrack',
        'company.legal_name' => null,
        'company.phone' => null,
        'company.email' => null,
        'company.address' => null,
        'company.logo_path' => null,

        'locale.digits.en' => 'latn',
        'locale.digits.ar' => 'arab',
        'locale.digits.ckb' => 'arab',

        'security.session_timeout_minutes' => 480,
        'security.require_admin_mfa' => env('ORTHOTRACK_REQUIRE_ADMIN_MFA', true),
        'security.max_login_attempts' => 5,

        'approvals.supplier_payment_threshold_usd' => 5000,
        'approvals.expense_threshold_usd' => 1000,

        'kits.overdue_days' => 7,

        // Morning summary (Phase 6): defaults for people who have not chosen their own.
        'summary.time' => '07:30',
        'summary.skip_fridays' => false,
        'summary.sections' => ['day', 'money', 'kits', 'stock', 'attention'],

        'fx.eur_usd_auto_fetch' => true,
        'fx.eur_usd_auto_confirm' => false,

        'features.commissions' => false,
        'features.credit_sales' => false,
        'features.price_lists' => false,
    ],

];
