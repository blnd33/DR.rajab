<?php

use App\Enums\AgentType;
use App\Enums\AttachmentKind;
use App\Enums\PlateKind;
use App\Enums\PlateRegion;
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
 * Company agents: distributors, direct sellers, representatives, partners, technicians and
 * employees (profiles kept by the super admin; nobody here signs in), and the drivers with
 * their trips. Money columns follow the usual numeric(18,4) + currency.
 */
return new class extends Migration
{
    private const NEW = ['agents.manage'];

    public function up(): void
    {
        Schema::create('company_agents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24);
            $table->string('name', 150);
            $table->string('phone', 32)->nullable();
            $table->string('city', 120)->nullable();
            // Technician: their (sign-in-less) technician account. Employee: optional link to their system account.
            $table->foreignId('user_id')->nullable()->unique()->constrained('users');
            $table->text('persons')->nullable();
            $table->string('stock', 255)->nullable();
            $table->decimal('value_amount', 18, 4)->nullable();
            $table->char('value_currency', 3)->nullable();
            $table->decimal('shares_percent', 7, 4)->nullable();
            $table->decimal('profit_month_amount', 18, 4)->nullable();
            $table->decimal('profit_year_amount', 18, 4)->nullable();
            $table->char('profit_currency', 3)->nullable();
            $table->string('position', 120)->nullable();
            $table->decimal('salary_amount', 18, 4)->nullable();
            $table->char('salary_currency', 3)->nullable();
            $table->decimal('mission_fee_amount', 18, 4)->nullable();
            $table->char('mission_fee_currency', 3)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['type', 'name']);
            $table->foreign('value_currency')->references('code')->on('currencies');
            $table->foreign('profit_currency')->references('code')->on('currencies');
            $table->foreign('salary_currency')->references('code')->on('currencies');
            $table->foreign('mission_fee_currency')->references('code')->on('currencies');
        });

        Pg::enumCheck('company_agents', 'type', AgentType::values());
        Pg::check('company_agents', 'company_agents_amounts_check', 'coalesce(value_amount, 0) >= 0 AND coalesce(salary_amount, 0) >= 0 AND coalesce(mission_fee_amount, 0) >= 0');
        Pg::check('company_agents', 'company_agents_shares_check', 'shares_percent IS NULL OR (shares_percent >= 0 AND shares_percent <= 100)');

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('phone', 32)->nullable();
            $table->string('car_type', 120)->nullable();
            // true = the company's car, false = the driver's own car.
            $table->boolean('company_car')->default(false);
            $table->string('plate_kind', 16);
            $table->string('plate_code', 2)->nullable();
            $table->string('plate_letter', 1)->nullable();
            $table->string('plate_number', 6);
            $table->string('plate_region', 24)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index('name');
        });

        Pg::enumCheck('drivers', 'plate_kind', PlateKind::values());
        Pg::enumCheck('drivers', 'plate_region', PlateRegion::values(), nullable: true);
        Pg::check('drivers', 'drivers_plate_check', "(plate_kind = 'english' AND plate_code IS NOT NULL AND plate_letter IS NOT NULL AND plate_region IS NULL)"
            ." OR (plate_kind = 'arabic' AND plate_region IS NOT NULL AND plate_code IS NULL AND plate_letter IS NULL)");

        Schema::create('driver_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers');
            $table->date('arrived_on');
            $table->date('received_on')->nullable();
            $table->decimal('amount', 18, 4);
            $table->char('currency', 3);
            $table->foreign('currency')->references('code')->on('currencies');
            $table->foreignId('fx_snapshot_id')->nullable()->constrained('fx_snapshots');
            $table->text('notes')->nullable();
            // Never deleted: a wrong trip is voided with a reason.
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users');
            $table->text('void_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();

            $table->index(['driver_id', 'arrived_on']);
        });

        Pg::check('driver_trips', 'driver_trips_amount_check', 'amount >= 0');
        Pg::check('driver_trips', 'driver_trips_dates_check', 'received_on IS NULL OR received_on >= arrived_on');

        $this->attachmentKinds(AttachmentKind::values());

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

        DB::table('attachments')->where('kind', AttachmentKind::Cv->value)->delete();
        $this->attachmentKinds(array_values(array_diff(AttachmentKind::values(), [AttachmentKind::Cv->value])));

        Schema::dropIfExists('driver_trips');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('company_agents');
    }

    /**
     * @param  list<string>  $kinds
     */
    private function attachmentKinds(array $kinds): void
    {
        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_kind_check');
        }

        Pg::enumCheck('attachments', 'kind', $kinds);
    }
};
