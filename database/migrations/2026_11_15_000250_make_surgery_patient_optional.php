<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The patient is optional on a surgery: a completed surgery still needs its hospital and type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE surgeries DROP CONSTRAINT IF EXISTS surgeries_complete_needs_patient');
        }

        Pg::check('surgeries', 'surgeries_complete_needs_hospital_and_type', "status <> 'completed' OR (customer_id IS NOT NULL AND surgery_type_id IS NOT NULL)");
    }

    public function down(): void
    {
        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE surgeries DROP CONSTRAINT IF EXISTS surgeries_complete_needs_hospital_and_type');
        }

        Pg::check('surgeries', 'surgeries_complete_needs_patient', "status <> 'completed' OR (patient_id IS NOT NULL AND customer_id IS NOT NULL AND surgery_type_id IS NOT NULL)");
    }
};
