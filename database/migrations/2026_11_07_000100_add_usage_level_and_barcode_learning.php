<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Supplier catalog (Lima / Enovis workbook): how often each item and instrument set is used,
 * and the permission to link a new box barcode to a product while receiving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('usage_level', 16)->nullable()->after('is_implant')->index();
        });

        Schema::table('kit_types', function (Blueprint $table) {
            $table->string('usage_level', 16)->nullable()->after('description');
        });

        // Whoever may receive stock may also teach the system a new box barcode.
        if (DB::table('permissions')->exists()) {
            $permission = Permission::findOrCreate('products.link_barcodes', 'web');

            Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'stock.receive'))
                ->get()
                ->each(fn (Role $role) => $role->givePermissionTo($permission));

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['usage_level']);
            $table->dropColumn('usage_level');
        });

        Schema::table('kit_types', function (Blueprint $table) {
            $table->dropColumn('usage_level');
        });
    }
};
