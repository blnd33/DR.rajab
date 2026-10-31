<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The patient is typed inside the surgery log. While the surgery is a draft the details stay on
 * the surgery (encrypted); the patient file is created when the surgery is completed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('surgeries', function (Blueprint $table) {
            $table->text('patient_details')->nullable()->after('patient_id');
        });
    }

    public function down(): void
    {
        Schema::table('surgeries', function (Blueprint $table) {
            $table->dropColumn('patient_details');
        });
    }
};
