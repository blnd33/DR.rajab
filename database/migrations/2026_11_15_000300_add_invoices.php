<?php

use App\Enums\InvoiceKind;
use App\Enums\PaymentTerms;
use App\Services\Permissions\PermissionRegistry;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Invoices for everything: sales and surgeries. An invoice can stay unpaid ("pay later") until
 * someone marks it paid into a box; only then the money counts as received.
 */
return new class extends Migration
{
    private const NEW = ['invoices.view_any', 'invoices.update', 'invoices.mark_paid'];

    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->string('kind', 16)->default(InvoiceKind::Sale->value);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users');
            $table->foreignId('paid_cash_box_id')->nullable()->constrained('cash_boxes');
            $table->foreignId('money_transaction_id')->nullable()->constrained('money_transactions');
            $table->index(['kind', 'status']);
        });

        Pg::enumCheck('sales_invoices', 'kind', InvoiceKind::values());

        // One live invoice per surgery (a voided one can be replaced). surgery_id existed as a billing hook.
        if (Pg::isPostgres()) {
            DB::statement("CREATE UNIQUE INDEX sales_invoices_one_live_per_surgery ON sales_invoices (surgery_id) WHERE surgery_id IS NOT NULL AND status <> 'voided'");
        }

        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_payment_terms_check');
        }

        Pg::enumCheck('sales_invoices', 'payment_terms', PaymentTerms::values());

        if (! DB::table('permissions')->exists()) {
            return;
        }

        foreach (self::NEW as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (PermissionRegistry::roleTemplates() as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $give = array_values(array_intersect(self::NEW, $permissions));

            if ($role !== null && $give !== []) {
                $role->givePermissionTo($give);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::NEW)->get()->each(fn (Permission $p) => $p->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_payment_terms_check');
            DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_kind_check');
            DB::statement('DROP INDEX IF EXISTS sales_invoices_one_live_per_surgery');
        }

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex(['kind', 'status']);
            $table->dropConstrainedForeignId('money_transaction_id');
            $table->dropConstrainedForeignId('paid_cash_box_id');
            $table->dropConstrainedForeignId('paid_by');
            $table->dropColumn(['paid_at', 'kind']);
        });

        Pg::enumCheck('sales_invoices', 'payment_terms', [PaymentTerms::Cash->value, PaymentTerms::Credit->value]);
    }
};
