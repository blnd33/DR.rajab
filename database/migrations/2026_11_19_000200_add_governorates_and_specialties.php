<?php

use App\Enums\Governorate;
use App\Enums\Specialty;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Main page overview: each station is in a governorate (Kurdistan Region or the rest of Iraq)
 * and each product category can belong to a specialty (arthroplasty, trauma, spine, sport
 * medicine). Existing rows are filled with a best guess from their names; both can be changed
 * on the station and category forms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->string('governorate', 24)->nullable()->after('city'));
        Pg::enumCheck('branches', 'governorate', Governorate::values(), nullable: true);

        Schema::table('categories', fn (Blueprint $table) => $table->string('specialty', 24)->nullable()->after('parent_id'));
        Pg::enumCheck('categories', 'specialty', Specialty::values(), nullable: true);

        foreach (DB::table('branches')->get(['id', 'city', 'name']) as $branch) {
            $guess = null;

            foreach ([$branch->city, $branch->name] as $json) {
                foreach ((array) json_decode((string) $json, true) as $text) {
                    $guess ??= Governorate::guess(is_string($text) ? $text : null);
                }
            }

            if ($guess !== null) {
                DB::table('branches')->where('id', $branch->id)->update(['governorate' => $guess->value]);
            }
        }

        foreach (DB::table('categories')->get(['id', 'code', 'name']) as $category) {
            $names = array_filter((array) json_decode((string) $category->name, true), 'is_string');
            $guess = Specialty::guess($category->code.' '.($names['en'] ?? implode(' ', $names)));

            if ($guess !== null) {
                DB::table('categories')->where('id', $category->id)->update(['specialty' => $guess->value]);
            }
        }
    }

    public function down(): void
    {
        if (Pg::isPostgres()) {
            DB::statement('ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_specialty_check');
            DB::statement('ALTER TABLE branches DROP CONSTRAINT IF EXISTS branches_governorate_check');
        }

        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('specialty'));
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('governorate'));
    }
};
