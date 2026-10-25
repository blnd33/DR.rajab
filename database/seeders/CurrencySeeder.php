<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'USD', 'symbol' => '$', 'decimals' => 2, 'sort' => 1, 'name' => ['en' => 'US Dollar', 'ar' => 'دولار أمريكي', 'ckb' => 'دۆلاری ئەمریکی']],
            ['code' => 'EUR', 'symbol' => '€', 'decimals' => 2, 'sort' => 2, 'name' => ['en' => 'Euro', 'ar' => 'يورو', 'ckb' => 'یۆرۆ']],
            ['code' => 'IQD', 'symbol' => 'IQD', 'decimals' => 0, 'sort' => 3, 'name' => ['en' => 'Iraqi Dinar', 'ar' => 'دينار عراقي', 'ckb' => 'دیناری عێراقی']],
        ];

        foreach ($currencies as $data) {
            Currency::query()->updateOrCreate(['code' => $data['code']], $data + ['is_active' => true]);
        }
    }
}
