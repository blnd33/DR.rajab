<?php

namespace Database\Seeders;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Seeder;

/**
 * Demo branches (local/staging only).
 */
class DemoBranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'code' => 'DHK',
                'name' => ['en' => 'Duhok', 'ar' => 'دهوك', 'ckb' => 'دهۆک'],
                'city' => ['en' => 'Duhok', 'ar' => 'دهوك', 'ckb' => 'دهۆک'],
                'phone' => '+964 750 000 0001',
                'default_currency' => 'USD',
                'is_head_office' => true,
            ],
            [
                'code' => 'EBL',
                'name' => ['en' => 'Erbil', 'ar' => 'أربيل', 'ckb' => 'هەولێر'],
                'city' => ['en' => 'Erbil', 'ar' => 'أربيل', 'ckb' => 'هەولێر'],
                'phone' => '+964 750 000 0002',
                'default_currency' => 'USD',
                'is_head_office' => false,
            ],
            [
                'code' => 'BGW',
                'name' => ['en' => 'Baghdad', 'ar' => 'بغداد', 'ckb' => 'بەغدا'],
                'city' => ['en' => 'Baghdad', 'ar' => 'بغداد', 'ckb' => 'بەغدا'],
                'phone' => '+964 770 000 0003',
                'default_currency' => 'IQD',
                'is_head_office' => false,
            ],
        ];

        foreach ($branches as $data) {
            $branch = Branch::query()->firstOrNew(['code' => $data['code']]);
            $branch->fill($data);
            $branch->status ??= BranchStatus::Active;
            $branch->save();
        }
    }
}
