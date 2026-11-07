<?php

namespace App\Services\Catalog;

use App\Enums\UsageLevel;
use App\Models\Category;
use App\Models\InstrumentType;
use App\Models\KitTemplateItem;
use App\Models\KitType;
use App\Support\Locale\LocaleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes what a supplier catalog brings besides products: the area → section categories,
 * instrument types, and instrument sets (kit types) with their expected contents.
 */
class SupplierCatalogService
{
    /** Area codes of the workbook → English name (translated through the language files). */
    public const AREAS = ['HIP' => 'Hip', 'KNEE' => 'Knee', 'SHOULDER' => 'Shoulder'];

    /**
     * "Hip → Acetabular Cups": the area category, and under it the section (both created
     * when missing). Without a section the area itself.
     */
    public function category(string $area, ?string $section): Category
    {
        $parent = Category::query()->where('code', $area)->first()
            ?? Category::query()->create([
                'code' => $area,
                'name' => $this->allLocales(self::AREAS[$area] ?? Str::title($area)),
                'is_active' => true,
                'sort' => array_search($area, array_keys(self::AREAS), true) ?: 0,
            ]);

        if (blank($section)) {
            return $parent;
        }

        $section = mb_substr(trim($section), 0, 120);

        $existing = Category::query()->where('parent_id', $parent->id)->get()
            ->first(fn (Category $c) => mb_strtolower($c->getTranslation('name', 'en', false)) === mb_strtolower($section));

        return $existing ?? Category::query()->create([
            'parent_id' => $parent->id,
            'code' => $this->uniqueCategoryCode($area, $section),
            'name' => ['en' => $section],
            'is_active' => true,
            'sort' => Category::query()->where('parent_id', $parent->id)->count() + 1,
        ]);
    }

    public function instrumentType(string $code, string $name): InstrumentType
    {
        $type = InstrumentType::query()->where('code', mb_strtoupper($code))->first() ?? new InstrumentType(['code' => $code, 'is_active' => true]);
        $type->setTranslation('name', 'en', mb_substr($name, 0, 191));
        $type->save();

        return $type;
    }

    /**
     * Create or update a set and replace its instrument lines (implant lines added by hand
     * stay). A set the doctor no longer uses is kept, inactive.
     *
     * @param  list<array{code: string, name: string, qty: int, required: bool}>  $items
     */
    public function syncKitType(string $code, string $name, ?string $description, ?UsageLevel $usage, array $items): KitType
    {
        return DB::transaction(function () use ($code, $name, $description, $usage, $items) {
            $kitType = KitType::query()->lockForUpdate()->where('code', mb_strtoupper($code))->first() ?? new KitType(['code' => $code]);
            $kitType->setTranslation('name', 'en', mb_substr($name, 0, 191));

            if ($description !== null) {
                $kitType->setTranslation('description', 'en', $description);
            }

            $kitType->usage_level = $usage;
            $kitType->is_active = $usage !== UsageLevel::NotUsed;
            $kitType->save();

            if ($items !== []) {
                $kitType->templateItems()->whereNull('product_id')->delete();

                foreach (array_values($items) as $position => $item) {
                    KitTemplateItem::query()->create([
                        'kit_type_id' => $kitType->id,
                        'instrument_type_id' => $this->instrumentType($item['code'], $item['name'])->id,
                        'expected_qty' => max(1, (int) $item['qty']),
                        'is_required' => (bool) $item['required'],
                        'sort' => $position + 1,
                    ]);
                }
            }

            return $kitType;
        });
    }

    /**
     * "HIP-ACETABULAR-CUPS" (max 32 characters, numbered when taken).
     */
    private function uniqueCategoryCode(string $area, string $section): string
    {
        $base = mb_substr(mb_strtoupper($area.'-'.Str::slug($section)), 0, 32);
        $code = $base;

        for ($n = 2; Category::query()->where('code', $code)->exists(); $n++) {
            $code = mb_substr($base, 0, 32 - mb_strlen("-{$n}"))."-{$n}";
        }

        return $code;
    }

    /**
     * @return array<string, string> locale → translated text
     */
    private function allLocales(string $english): array
    {
        $names = [];

        foreach (array_keys(app(LocaleRegistry::class)->all()) as $locale) {
            $names[$locale] = __($english, [], $locale);
        }

        return $names;
    }
}
