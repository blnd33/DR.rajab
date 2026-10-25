<?php

namespace Database\Seeders;

use App\Enums\LabelSymbology;
use App\Models\ExpiryLevel;
use App\Models\LabelTemplate;
use App\Models\Uom;
use App\Services\Expiry\ExpiryLevelService;
use Illuminate\Database\Seeder;

/**
 * Phase 1 base data, safe in production and idempotent: units of measure, the default
 * expiry levels (only when none exist — never overwrites the Super Admin's levels) and
 * two thermal label templates.
 */
class InventoryBaseSeeder extends Seeder
{
    public function run(ExpiryLevelService $expiryLevels): void
    {
        $uoms = [
            'pc' => ['en' => 'Piece', 'ar' => 'قطعة', 'ckb' => 'دانە'],
            'box' => ['en' => 'Box', 'ar' => 'علبة', 'ckb' => 'قوتو'],
            'pack' => ['en' => 'Pack', 'ar' => 'عبوة', 'ckb' => 'پاکەت'],
            'set' => ['en' => 'Set', 'ar' => 'طقم', 'ckb' => 'سێت'],
        ];

        foreach ($uoms as $code => $name) {
            Uom::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }

        if (! ExpiryLevel::query()->exists()) {
            $expiryLevels->saveAll(ExpiryLevelService::defaults());
        }

        if (! LabelTemplate::query()->exists()) {
            LabelTemplate::query()->create([
                'name' => 'Small 50 × 25 — Code 128',
                'width_mm' => 50,
                'height_mm' => 25,
                'symbology' => LabelSymbology::Code128,
                'layout' => ['fields' => ['name', 'sku', 'serial', 'lot', 'expiry'], 'font_pt' => 6],
                'is_default' => true,
            ]);

            LabelTemplate::query()->create([
                'name' => 'Square 40 × 30 — GS1 DataMatrix',
                'width_mm' => 40,
                'height_mm' => 30,
                'symbology' => LabelSymbology::Gs1DataMatrix,
                'layout' => ['fields' => ['name', 'size', 'serial', 'lot', 'expiry'], 'font_pt' => 6],
                'is_default' => false,
            ]);
        }
    }
}
