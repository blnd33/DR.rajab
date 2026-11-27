<?php

use App\Enums\Specialty;
use App\Support\Database\Pg;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogues by category: a catalogue folder (one supplier company: Lima, Mikron…) belongs to a
 * specialty — Arthroplasty, Trauma, Spine or Sport medicine. Lima → Arthroplasty, Mikron → Trauma;
 * the others are sorted in Administration → Document folders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_folders', function (Blueprint $table) {
            $table->string('specialty', 24)->nullable()->after('section');
        });

        Pg::enumCheck('document_folders', 'specialty', Specialty::values(), nullable: true);

        $known = ['lima' => Specialty::Arthroplasty->value, 'mikron' => Specialty::Trauma->value];

        foreach (DB::table('document_folders')->where('section', 'catalogues')->get(['id', 'name']) as $folder) {
            $english = strtolower(trim((string) (json_decode((string) $folder->name, true)['en'] ?? '')));

            if (isset($known[$english])) {
                DB::table('document_folders')->where('id', $folder->id)->update(['specialty' => $known[$english]]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('document_folders', function (Blueprint $table) {
            $table->dropColumn('specialty');
        });
    }
};
