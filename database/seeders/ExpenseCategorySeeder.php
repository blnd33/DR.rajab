<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

/**
 * Standard expense categories (all environments, idempotent). Admins add more or switch
 * these off; codes never change.
 */
class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['RENT', 'Rent', 'الإيجار', 'کرێ'],
            ['SALARY', 'Salaries', 'الرواتب', 'مووچە'],
            ['UTIL', 'Electricity, water & internet', 'الكهرباء والماء والإنترنت', 'کارەبا و ئاو و ئینتەرنێت'],
            ['FUEL', 'Fuel & generator', 'الوقود والمولدة', 'سووتەمەنی و مۆلیدە'],
            ['TRANSPORT', 'Transport & delivery', 'النقل والتوصيل', 'گواستنەوە و گەیاندن'],
            ['MAINT', 'Maintenance & repairs', 'الصيانة والتصليح', 'چاککردنەوە'],
            ['STERIL', 'Sterilisation & cleaning', 'التعقيم والتنظيف', 'پاکژکردنەوە و خاوێنکردنەوە'],
            ['OFFICE', 'Office supplies', 'مستلزمات المكتب', 'پێداویستی نووسینگە'],
            ['PHONE', 'Phone & communication', 'الهاتف والاتصالات', 'تەلەفۆن و پەیوەندی'],
            ['BANKFEE', 'Bank & transfer fees', 'رسوم البنك والتحويل', 'کرێی بانک و حەواڵە'],
            ['LICENSE', 'Licences, taxes & government fees', 'التراخيص والضرائب والرسوم الحكومية', 'مۆڵەت و باج و ڕسوماتی حکومی'],
            ['TRAINING', 'Training & conferences', 'التدريب والمؤتمرات', 'ڕاهێنان و کۆنفرانس'],
            ['HOSPITALITY', 'Hospitality', 'الضيافة', 'میوانداری'],
            ['OTHER', 'Other', 'أخرى', 'هیتر'],
        ];

        foreach ($categories as $sort => [$code, $en, $ar, $ckb]) {
            if (ExpenseCategory::query()->where('code', $code)->exists()) {
                continue;
            }

            ExpenseCategory::query()->create([
                'code' => $code,
                'name' => ['en' => $en, 'ar' => $ar, 'ckb' => $ckb],
                'sort' => ($sort + 1) * 10,
                'is_active' => true,
            ]);
        }
    }
}
