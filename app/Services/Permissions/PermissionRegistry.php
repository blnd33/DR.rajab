<?php

namespace App\Services\Permissions;

use App\Enums\Role;

/**
 * The single list of permissions ("what") for every phase, and the role templates.
 *
 * Name format: "domain.action". `scoped` = the permission is limited by branch ("where");
 * unscoped permissions are global (e.g. editing the product catalog).
 *
 * Labels are English source strings translated through lang/*.json.
 */
final class PermissionRegistry
{
    /**
     * @return array<string, array{group: string, label: string, scoped: bool}>
     */
    public static function all(): array
    {
        $definitions = [
            'access' => [
                'admin.access' => ['Open the admin panel', false],
                'ops.access' => ['Open the operations app', false],
                'dashboard.view' => ['View the admin dashboard', true],
            ],
            'users' => [
                'users.view_any' => ['View users', false],
                'users.create' => ['Create users', false],
                'users.update' => ['Edit users', false],
                'users.deactivate' => ['Deactivate users', false],
                'users.manage_sessions' => ['Log out user devices', false],
                'users.manage_permissions' => ['Change user permissions and branch scope', false],
                'roles.view_any' => ['View roles', false],
                'roles.manage' => ['Create and edit roles', false],
            ],
            'branches' => [
                'branches.view_any' => ['View branches', false],
                'branches.create' => ['Add branches', false],
                'branches.update' => ['Edit branches', false],
                'branches.close' => ['Close and reopen branches', false],
                'branches.delete' => ['Delete empty branches', false],
            ],
            'settings' => [
                'settings.manage' => ['Edit system settings', false],
                'settings.expiry_levels' => ['Edit expiry levels and colors', false],
                'settings.lists' => ['Edit lists (categories, surgery types, kit types…)', false],
                'activity.view_any' => ['View the activity log', true],
                'system.health' => ['View system health and backups', false],
            ],
            'catalog' => [
                'products.view_any' => ['View the product catalog', false],
                'products.manage' => ['Create and edit products', false],
                'products.import' => ['Import products from Excel', false],
                'products.export' => ['Export products to Excel', false],
                'products.print_labels' => ['Print barcode labels', true],
                'labels.templates' => ['Edit label layouts', false],
            ],
            'stock' => [
                'stock.view' => ['View stock', true],
                'stock.view_cost' => ['View stock cost and value', true],
                'stock.receive' => ['Enter opening stock (receive without a purchase)', true],
                'stock.correct' => ['Correct lot, expiry, cost or shelf (with a reason)', true],
                'stock.adjust' => ['Adjust stock after a count', true],
                'stock.recall' => ['Recall search by lot', false],
                'stock.override_expired' => ['Use or move expired units (with reason)', true],
                'stock.import' => ['Import stock from Excel', true],
                'stock.export' => ['Export stock to Excel', true],
                'stock.min_levels' => ['Edit minimum stock levels', true],
            ],
            'transfers' => [
                'transfers.view_any' => ['View transfers', true],
                'transfers.create' => ['Create transfers', true],
                'transfers.send' => ['Send transfers', true],
                'transfers.receive' => ['Receive transfers', true],
                'transfers.cancel' => ['Cancel transfers before receiving', true],
                'stock_requests.view_any' => ['View stock requests', true],
                'stock_requests.create' => ['Request stock from another branch', true],
                'stock_requests.approve' => ['Approve or reject stock requests', true],
            ],
            'returns' => [
                'returns.view_any' => ['View returns', true],
                'returns.customer' => ['Receive returns from hospitals and customers', true],
                'returns.supplier' => ['Return items to suppliers', true],
                'write_offs.view_any' => ['View write-offs', true],
                'write_offs.report' => ['Report damaged, lost or expired items', true],
                'write_offs.approve' => ['Approve write-offs', true],
            ],
            'counts' => [
                'stock_counts.view_any' => ['View stock counts', true],
                'stock_counts.start' => ['Start a stock count', true],
                'stock_counts.scan' => ['Scan items in a stock count', true],
                'stock_counts.review' => ['Review stock count results', true],
            ],
            'clinical' => [
                'customers.view_any' => ['View hospitals and customers', false],
                'customers.manage' => ['Create and edit hospitals and customers', false],
                'patients.view_own' => ['View own patients', true],
                'patients.view_all' => ['View all patients', true],
                'patients.manage' => ['Create and edit patients', true],
                'surgeries.view_any' => ['View surgeries', true],
                'surgeries.create' => ['Log surgeries', true],
                'surgeries.cancel' => ['Cancel surgeries', true],
            ],
            'kits' => [
                'kits.view_any' => ['View kits and instruments', true],
                'kits.manage' => ['Create and edit kits', true],
                'kits.prepare' => ['Prepare and refill kits', true],
                'kits.checkout' => ['Check out kits to hospitals', true],
                'kits.return' => ['Return and reconcile kits', true],
                'kits.resolve_unaccounted' => ['Resolve unaccounted implants', true],
                'instruments.manage' => ['Create and edit instruments', true],
            ],
            'money' => [
                'cash_boxes.view_any' => ['View cash boxes', true],
                'cash_boxes.manage' => ['Create and edit cash boxes', true],
                'cash_boxes.close_day' => ['Daily cash closing', true],
                'cash_boxes.review_closing' => ['Review daily closings', true],
                'cash_boxes.transfer' => ['Move cash between boxes', true],
                'currency_exchange.create' => ['Exchange currency between boxes', true],
                'exchange_rates.view_any' => ['View exchange rates', false],
                'exchange_rates.set_usd_iqd' => ['Set the USD/IQD rate', false],
                'exchange_rates.set_eur_usd' => ['Set the EUR/USD rate', false],
            ],
            'purchasing' => [
                'suppliers.view_any' => ['View suppliers', false],
                'suppliers.manage' => ['Create and edit suppliers', false],
                'purchases.view_any' => ['View purchases', true],
                'purchases.create' => ['Create purchase invoices', true],
                'purchases.receive' => ['Receive purchased items into stock', true],
                'purchases.landed_costs' => ['Add landed costs', true],
                'supplier_payments.create' => ['Record supplier payments', false],
                'supplier_payments.approve' => ['Approve large supplier payments', false],
            ],
            'sales' => [
                'sales.view_any' => ['View sales', true],
                'sales.create' => ['Create sales', true],
                'sales.void' => ['Void sales', true],
                'customer_payments.create' => ['Receive customer payments', true],
                'expenses.view_any' => ['View expenses', true],
                'expenses.create' => ['Record expenses', true],
                'expenses.approve' => ['Approve large expenses', true],
                'expenses.recurring' => ['Manage recurring expenses', true],
                'commissions.manage' => ['Manage doctor commissions', false],
            ],
            'reports' => [
                'reports.view' => ['View reports', true],
                'reports.profit' => ['View profit reports', true],
                'reports.export' => ['Export reports', true],
            ],
        ];

        $permissions = [];

        foreach ($definitions as $group => $items) {
            foreach ($items as $name => [$label, $scoped]) {
                $permissions[$name] = ['group' => $group, 'label' => $label, 'scoped' => $scoped];
            }
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $permission): bool
    {
        return array_key_exists($permission, self::all());
    }

    public static function isScoped(string $permission): bool
    {
        return self::all()[$permission]['scoped'] ?? true;
    }

    /**
     * group => [name => translated label]
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::all() as $name => $definition) {
            $grouped[$definition['group']][$name] = __($definition['label']);
        }

        return $grouped;
    }

    public static function groupLabel(string $group): string
    {
        return match ($group) {
            'access' => __('Access'),
            'users' => __('Users & roles'),
            'branches' => __('Branches'),
            'settings' => __('Settings & system'),
            'catalog' => __('Product catalog'),
            'stock' => __('Stock'),
            'transfers' => __('Transfers & requests'),
            'returns' => __('Returns & write-offs'),
            'counts' => __('Stock counts'),
            'clinical' => __('Hospitals, patients & surgeries'),
            'kits' => __('Kits & instruments'),
            'money' => __('Cash & currency'),
            'purchasing' => __('Suppliers & purchases'),
            'sales' => __('Sales & expenses'),
            'reports' => __('Reports'),
            default => $group,
        };
    }

    /**
     * Role templates. Super Admin gets everything through Gate::before.
     *
     * @return array<string, list<string>>
     */
    public static function roleTemplates(): array
    {
        return [
            Role::SuperAdmin->value => [],

            Role::Accountant->value => [
                'admin.access', 'dashboard.view',
                'branches.view_any', 'activity.view_any',
                'products.view_any', 'products.export',
                'stock.view', 'stock.view_cost', 'stock.export', 'stock.recall', 'stock.correct',
                'transfers.view_any', 'stock_requests.view_any', 'returns.view_any', 'write_offs.view_any', 'stock_counts.view_any',
                'customers.view_any', 'customers.manage',
                'cash_boxes.view_any', 'cash_boxes.manage', 'cash_boxes.review_closing', 'cash_boxes.transfer',
                'exchange_rates.view_any', 'exchange_rates.set_usd_iqd', 'exchange_rates.set_eur_usd',
                'suppliers.view_any', 'suppliers.manage',
                'purchases.view_any', 'purchases.create', 'purchases.landed_costs',
                'supplier_payments.create',
                'sales.view_any',
                'expenses.view_any', 'expenses.create', 'expenses.recurring',
                'reports.view', 'reports.profit', 'reports.export',
            ],

            Role::Doctor->value => [
                'ops.access',
                'products.view_any', 'stock.view', 'stock.recall',
                'transfers.view_any', 'stock_requests.view_any', 'stock_requests.create',
                'returns.customer', 'write_offs.report',
                'customers.view_any',
                'patients.view_own', 'patients.manage',
                'surgeries.view_any', 'surgeries.create',
                'kits.view_any', 'kits.return',
            ],

            Role::Cashier->value => [
                'ops.access',
                'stock.view', 'products.view_any',
                'customers.view_any',
                'returns.customer',
                'cash_boxes.view_any', 'cash_boxes.close_day',
                'currency_exchange.create', 'exchange_rates.view_any',
                'sales.view_any', 'sales.create', 'customer_payments.create',
                'expenses.view_any', 'expenses.create',
            ],

            Role::BranchStaff->value => [
                'ops.access',
                'products.view_any', 'products.print_labels',
                'stock.view', 'stock.receive', 'stock.recall',
                'transfers.view_any', 'transfers.create', 'transfers.send', 'transfers.receive', 'transfers.cancel',
                'stock_requests.view_any', 'stock_requests.create',
                'returns.view_any', 'returns.customer', 'returns.supplier',
                'write_offs.view_any', 'write_offs.report',
                'stock_counts.view_any', 'stock_counts.start', 'stock_counts.scan',
                'customers.view_any',
                'kits.view_any', 'kits.prepare', 'kits.checkout', 'kits.return', 'instruments.manage',
                'purchases.receive',
            ],
        ];
    }
}
