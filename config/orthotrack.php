<?php

use App\Services\Barcode\Parsers\Gs1Parser;
use App\Services\Barcode\Parsers\InternalLabelParser;
use App\Services\Barcode\Parsers\PlainCodeParser;
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

        'fx.eur_usd_auto_fetch' => true,
        'fx.eur_usd_auto_confirm' => false,

        'features.commissions' => false,
        'features.credit_sales' => false,
        'features.price_lists' => false,
    ],

];
