<?php

namespace App\Services\Companies;

use App\Enums\Company;
use App\Enums\Governorate;
use App\Models\Branch;
use App\Services\Branches\BranchService;
use App\Services\Settings\SettingsRepository;
use App\Support\CompanyContext;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Prepares the database of the company this process works in (CompanyContext): its tables, the
 * base data (currencies, roles and permissions, settings, units…), its name, and its stations
 * (config orthotrack.companies.<company>.stations). Safe to run again: nothing is added twice.
 */
class CompanySetupService
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  callable(string): void|null  $say
     * @return array{company: Company, stations: int}
     */
    public function setup(?callable $say = null): array
    {
        $say ??= fn (string $line) => null;
        $company = CompanyContext::current();

        Artisan::call('migrate', ['--force' => true]);
        $say('Tables ready.');

        foreach (DatabaseSeeder::BASE as $seeder) {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }
        $say('Base data ready (currencies, roles and permissions, settings, units).');

        // Still the system's default name (OrthoTrack / APP_NAME): give it the company's name.
        $name = (string) $this->settings->get('company.name', '');
        if (in_array($name, ['', (string) config('app.name'), (string) ($this->settings->defaults()['company.name'] ?? '')], true)) {
            $this->settings->set('company.name', $company->getLabel());
        }

        $added = 0;
        foreach ((array) config("orthotrack.companies.{$company->value}.stations", []) as $code => [$en, $ar, $ckb, $governorate]) {
            if (Branch::query()->where('code', $code)->exists()) {
                continue;
            }

            $this->branches->create([
                'code' => $code,
                'name' => ['en' => $en, 'ar' => $ar, 'ckb' => $ckb],
                'city' => ['en' => $en, 'ar' => $ar, 'ckb' => $ckb],
                'governorate' => Governorate::from($governorate),
                'default_currency' => 'USD',
            ]);
            $added++;
        }
        $say(sprintf('Stations: %d new, %d in all.', $added, Branch::query()->count()));

        return ['company' => $company, 'stations' => $added];
    }
}
