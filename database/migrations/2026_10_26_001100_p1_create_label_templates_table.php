<?php

use App\Enums\LabelSymbology;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thermal label layouts (50×25 mm, 40×30 mm…). layout = which fields and font sizes.
        Schema::create('label_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->decimal('width_mm', 6, 2);
            $table->decimal('height_mm', 6, 2);
            $table->string('symbology', 32);
            $table->jsonb('layout');
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::enumCheck('label_templates', 'symbology', LabelSymbology::values());
        Pg::check('label_templates', 'label_templates_size', 'width_mm BETWEEN 10 AND 200 AND height_mm BETWEEN 10 AND 200');
        DB::statement('CREATE UNIQUE INDEX label_templates_single_default ON label_templates (is_default) WHERE is_default = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('label_templates');
    }
};
