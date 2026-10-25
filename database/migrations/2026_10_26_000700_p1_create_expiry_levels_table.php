<?php

use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable levels (Module 6). ExpiryLevelService keeps the set contiguous:
        // no gaps, no overlaps, last level open-ended, "Expired" fixed at <= 0 days.
        Schema::create('expiry_levels', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->integer('min_days')->nullable();
            $table->integer('max_days')->nullable();
            $table->char('color', 7);
            $table->char('text_color', 7);
            $table->smallInteger('sort')->default(0);
            $table->boolean('is_expired_level')->default(false);
            $table->boolean('blocks_usage')->default(false);
            $table->boolean('notify_on_enter')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });

        Pg::check('expiry_levels', 'expiry_levels_name_has_en', "name ? 'en'");
        Pg::check('expiry_levels', 'expiry_levels_colors_hex', "color ~ '^#[0-9A-Fa-f]{6}$' AND text_color ~ '^#[0-9A-Fa-f]{6}$'");
        Pg::check('expiry_levels', 'expiry_levels_range', 'min_days IS NULL OR max_days IS NULL OR min_days <= max_days');
        Pg::check('expiry_levels', 'expiry_levels_expired_rule', 'NOT is_expired_level OR (min_days IS NULL AND max_days = 0 AND blocks_usage)');

        // Exactly one "Expired" level (the service also refuses deleting it).
        DB::statement('CREATE UNIQUE INDEX expiry_levels_single_expired ON expiry_levels (is_expired_level) WHERE is_expired_level = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('expiry_levels');
    }
};
