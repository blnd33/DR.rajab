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
                'dashboard.view' => ['View the admin main page', true],
            ],
            'users' => [
                'users.view_any' => ['View users', false],
                'users.create' => ['Create users', false],
                'users.update' => ['Edit users', false],
                'users.deactivate' => ['Deactivate users', false],
                'users.manage_sessions' => ['Log out user devices', false],
                'users.manage_permissions' => ['Change user permissions and station scope', false],
                'roles.view_any' => ['View roles', false],
                'roles.manage' => ['Create and edit roles', false],
            ],
            'branches' => [
                'branches.view_any' => ['View stations', false],
                'branches.create' => ['Add stations', false],
                'branches.update' => ['Edit stations', false],
                'branches.close' => ['Close and reopen stations', false],
                'branches.delete' => ['Delete empty stations', false],
            ],
            'settings' => [
                'settings.manage' => ['Edit system settings', false],
                'settings.expiry_levels' => ['Edit expiry levels and colors', false],
                'settings.lists' => ['Edit lists (categories, surgery types, instrument set types…)', false],
                'activity.view_any' => ['View the activity log', true],
                'system.health' => ['View system health and backups', false],
            ],
            'catalog' => [
                'products.view_any' => ['View the product catalog', false],
                'products.manage' => ['Create and edit products', false],
                'products.import' => ['Import products from Excel', false],
                'products.export' => ['Export products to Excel', false],
                'products.print_labels' => ['Print barcode labels', true],
                'products.link_barcodes' => ['Link a new box barcode to a product while receiving', false],
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
                'stock_requests.create' => ['Request stock from another station', true],
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
                'issues.view_any' => ['View the issues queue', true],
                'issues.resolve' => ['Resolve issues', false],
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
                'technicians.manage' => ['Add and edit technicians', false],
            ],
            'kits' => [
                'kits.view_any' => ['View instrument sets and instruments', true],
                'kits.manage' => ['Create and edit instrument sets', true],
                'kits.prepare' => ['Prepare and refill instrument sets', true],
                'kits.checkout' => ['Check out instrument sets to hospitals', true],
                'kits.return' => ['Return and reconcile instrument sets', true],
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
                'invoices.view_any' => ['View invoices (sales and surgeries)', true],
                'invoices.update' => ['Edit unpaid invoices', true],
                'invoices.mark_paid' => ['Mark invoices paid (or not paid)', true],
                'customer_payments.create' => ['Receive customer payments', true],
                'expenses.view_any' => ['View expenses', true],
                'expenses.create' => ['Record expenses', true],
                'expenses.update' => ['Edit expenses (a paid one is corrected in its box)', true],
                'expenses.approve' => ['Approve large expenses', true],
                'expenses.recurring' => ['Manage recurring expenses', true],
                'commissions.manage' => ['Manage doctor commissions', false],
            ],
            'reports' => [
                'reports.view' => ['View reports', true],
                'reports.profit' => ['View profit reports', true],
                'reports.export' => ['Export reports', true],
            ],
            'agents' => [
                'agents.manage' => ['Manage company agents and drivers', false],
            ],
            'insight' => [
                'summary.receive' => ['Receive the morning report', false],
                'assistant.use' => ['Use Quick answers', false],
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
            'branches' => __('Stations'),
            'settings' => __('Settings & system'),
            'catalog' => __('Product catalog'),
            'stock' => __('Stock'),
            'transfers' => __('Transfers & requests'),
            'returns' => __('Returns & write-offs'),
            'counts' => __('Stock counts'),
            'clinical' => __('Hospitals, patients & surgeries'),
            'kits' => __('Instrument sets & instruments'),
            'money' => __('Cash & currency'),
            'purchasing' => __('Suppliers & purchases'),
            'sales' => __('Sales & expenses'),
            'reports' => __('Reports'),
            'agents' => __('Company agents'),
            'insight' => __('Morning report & Quick answers'),
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

            // Sees and does everything the system offers (an editable role, unlike Super Admin).
            Role::Manager->value => self::names(),

            Role::Accountant->value => [
                'admin.access', 'dashboard.view',
                'branches.view_any', 'activity.view_any',
                'products.view_any', 'products.export',
                'stock.view', 'stock.view_cost', 'stock.export', 'stock.recall', 'stock.correct',
                'transfers.view_any', 'stock_requests.view_any', 'returns.view_any', 'write_offs.view_any', 'stock_counts.view_any', 'issues.view_any',
                'customers.view_any', 'customers.manage',
                'cash_boxes.view_any', 'cash_boxes.manage', 'cash_boxes.review_closing', 'cash_boxes.transfer',
                'exchange_rates.view_any', 'exchange_rates.set_usd_iqd', 'exchange_rates.set_eur_usd',
                'suppliers.view_any', 'suppliers.manage',
                'purchases.view_any', 'purchases.create', 'purchases.landed_costs',
                'supplier_payments.create',
                'sales.view_any',
                'invoices.view_any', 'invoices.update', 'invoices.mark_paid',
                'expenses.view_any', 'expenses.create', 'expenses.update', 'expenses.recurring',
                'reports.view', 'reports.profit', 'reports.export',
                'summary.receive', 'assistant.use',
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
                'assistant.use',
            ],

            Role::Cashier->value => [
                'ops.access',
                'stock.view', 'products.view_any',
                'customers.view_any',
                'returns.customer',
                'cash_boxes.view_any', 'cash_boxes.close_day',
                'currency_exchange.create', 'exchange_rates.view_any',
                'sales.view_any', 'sales.create', 'customer_payments.create',
                'invoices.view_any', 'invoices.mark_paid',
                'expenses.view_any', 'expenses.create',
                'assistant.use',
            ],

            // Technicians never sign in: no permissions, ever (RoleService keeps it empty).
            Role::Technician->value => [],

            Role::BranchStaff->value => [
                'ops.access',
                'products.view_any', 'products.print_labels', 'products.link_barcodes',
                'stock.view', 'stock.receive', 'stock.recall',
                'transfers.view_any', 'transfers.create', 'transfers.send', 'transfers.receive', 'transfers.cancel',
                'stock_requests.view_any', 'stock_requests.create',
                'returns.view_any', 'returns.customer', 'returns.supplier',
                'write_offs.view_any', 'write_offs.report',
                'stock_counts.view_any', 'stock_counts.start', 'stock_counts.scan',
                'customers.view_any',
                'kits.view_any', 'kits.prepare', 'kits.checkout', 'kits.return', 'instruments.manage',
                'purchases.receive',
                'assistant.use',
            ],
        ];
    }
}
