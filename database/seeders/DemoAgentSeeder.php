<?php

namespace Database\Seeders;

use App\Enums\AgentType;
use App\Enums\Role;
use App\Models\CompanyAgent;
use App\Models\User;
use App\Services\Agents\CompanyAgentService;
use App\Services\Agents\DriverService;
use App\Services\Users\TechnicianService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Demo company agents (one or two of each kind), the demo technicians' CV-less profiles, and
 * two drivers — one with an English plate, one with an Arabic plate — with a few trips.
 */
class DemoAgentSeeder extends Seeder
{
    public function run(CompanyAgentService $agents, DriverService $drivers): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo agents are never created in production.');
        }

        if (CompanyAgent::query()->exists()) {
            return;
        }

        $admin = User::query()->where('username', 'superadmin')->first()
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->firstOrFail();
        Auth::setUser($admin);

        $agents->create(AgentType::Distributor, ['name' => 'Zagros Medical Supplies', 'phone' => '+964 750 000 0301', 'city' => 'Erbil'], $admin);
        $agents->create(AgentType::Distributor, ['name' => 'Nawroz Pharma', 'phone' => '+964 750 000 0302', 'city' => 'Sulaymaniyah'], $admin);
        $agents->create(AgentType::DirectSeller, ['name' => 'Hozan Ali', 'phone' => '+964 750 000 0311', 'city' => 'Zakho', 'persons' => 'Hozan Ali, Sipan Omar',
            'value_amount' => '18000', 'value_currency' => 'USD', 'stock' => '12 knee and 6 hip implants'], $admin);
        $agents->create(AgentType::Representative, ['name' => 'Dilan Rashid', 'phone' => '+964 750 000 0321',
            'salary_amount' => '900000', 'salary_currency' => 'IQD', 'mission_fee_amount' => '25', 'mission_fee_currency' => 'USD'], $admin);
        $agents->create(AgentType::Partner, ['name' => 'Rebaz Karim', 'phone' => '+964 750 000 0331', 'city' => 'Duhok', 'shares_percent' => '25',
            'value_amount' => '150000', 'value_currency' => 'USD', 'profit_month_amount' => '4000', 'profit_currency' => 'USD'], $admin);
        $agents->create(AgentType::Employee, ['name' => 'Shilan Aziz', 'phone' => '+964 750 000 0341', 'position' => 'Store keeper',
            'salary_amount' => '750000', 'salary_currency' => 'IQD'], $admin);

        foreach (app(TechnicianService::class)->query()->get() as $i => $technician) {
            $agents->saveTechnicianProfile($technician, ['position' => 'Scrub technician', 'salary_amount' => (string) (1000000 + $i * 100000), 'salary_currency' => 'IQD'], $admin);
        }

        $sherwan = $drivers->create(['name' => 'Sherwan Mohammed', 'phone' => '+964 750 000 0351', 'car_type' => 'Toyota Hilux', 'company_car' => true,
            'plate_kind' => 'english', 'plate_code' => '24', 'plate_letter' => 'A', 'plate_number' => '3003'], $admin);
        $hawre = $drivers->create(['name' => 'Hawre Jalal', 'phone' => '+964 750 000 0352', 'car_type' => 'Kia Bongo', 'company_car' => false,
            'plate_kind' => 'arabic', 'plate_number' => '12345', 'plate_region' => 'duhok'], $admin);

        $today = now(config('orthotrack.timezone'));
        $drivers->addTrip($sherwan, ['arrived_on' => $today->copy()->subDays(9)->toDateString(), 'received_on' => $today->copy()->subDays(8)->toDateString(), 'amount' => '150000', 'currency' => 'IQD', 'notes' => 'Implants from Erbil'], $admin);
        $drivers->addTrip($sherwan, ['arrived_on' => $today->copy()->subDays(2)->toDateString(), 'amount' => '120', 'currency' => 'USD'], $admin);
        $drivers->addTrip($hawre, ['arrived_on' => $today->copy()->subDays(5)->toDateString(), 'received_on' => $today->copy()->subDays(5)->toDateString(), 'amount' => '200000', 'currency' => 'IQD'], $admin);
    }
}
