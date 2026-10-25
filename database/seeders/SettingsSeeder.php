<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Seeder;

/**
 * Writes defaults for keys that have no row yet (never overwrites edited settings).
 */
class SettingsSeeder extends Seeder
{
    public function run(SettingsRepository $settings): void
    {
        $missing = [];

        foreach ($settings->defaults() as $key => $value) {
            [$group, $name] = explode('.', $key, 2);

            if (! Setting::query()->where('group', $group)->where('key', $name)->exists()) {
                $missing[$key] = $value;
            }
        }

        if ($missing !== []) {
            $settings->setMany($missing);
        }
    }
}
