<?php

namespace Database\Seeders;

use App\Enums\Gender;
use App\Enums\LocationType;
use App\Enums\Role;
use App\Enums\StockStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\InstrumentType;
use App\Models\Kit;
use App\Models\KitType;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\SurgeryType;
use App\Models\User;
use App\Services\Assets\InstrumentService;
use App\Services\Assets\KitService;
use App\Services\Clinical\PatientService;
use App\Services\Clinical\SurgeryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Demo clinical data (local/staging only), made through the real services: surgery types,
 * kit types with templates, kits and instruments, patients, surgeries (from a shelf and
 * from a kit), a kit out at a hospital (overdue) and a draft with a problem.
 */
class DemoClinicalSeeder extends Seeder
{
    public function run(KitService $kits, InstrumentService $instruments, PatientService $patients, SurgeryService $surgeries): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo clinical data is never created in production.');
        }

        $admin = User::query()->where('username', 'superadmin')->first()
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::SuperAdmin->value))->firstOrFail();
        $doctor = User::query()->where('username', 'doctor')->first() ?? $admin;
        $staff = User::query()->where('username', 'staff')->first() ?? $admin;
        $dhk = Branch::query()->where('code', 'DHK')->firstOrFail();
        $ebl = Branch::query()->where('code', 'EBL')->firstOrFail();
        $azadi = Customer::query()->where('code', 'HSP-AZADI')->first();
        $rizgary = Customer::query()->where('code', 'HSP-RIZGARY')->first();

        if ($azadi === null || $rizgary === null) {
            return; // needs DemoMovementSeeder's hospitals
        }

        $types = $this->surgeryTypes();
        $instrumentTypes = $this->instrumentTypes();
        $kitTypes = $this->kitTypes($instrumentTypes);

        $types['TKA']->update(['default_kit_type_id' => $kitTypes['KNEE']->id]);
        $types['THA']->update(['default_kit_type_id' => $kitTypes['HIP']->id]);

        // Instruments and kits.
        $make = fn (Branch $branch, string $type) => $instruments->create($branch, ['instrument_type_id' => $instrumentTypes[$type]->id, 'manufacturer_serial' => 'MFR-'.Str::upper(Str::random(6))], $admin);
        $guide = $make($dhk, 'GUIDE');
        $trial = $make($dhk, 'TRIAL');
        $reamer = $make($dhk, 'REAMER');
        $make($dhk, 'DRILL');
        $eblGuide = $make($ebl, 'GUIDE');

        $knee = $kits->create($dhk, ['kit_type_id' => $kitTypes['KNEE']->id, 'notes' => 'Primary knee set, blue case'], $admin);
        $hip = $kits->create($dhk, ['kit_type_id' => $kitTypes['HIP']->id, 'notes' => 'Hip set'], $admin);
        $eblKnee = $kits->create($ebl, ['kit_type_id' => $kitTypes['KNEE']->id], $admin);

        // Prepare: the knee kit complete, the hip kit with one implant missing, EBL only instruments.
        $kits->addInstrument($knee, $guide, $staff);
        $kits->addInstrument($knee, $trial, $staff);
        $this->loadUnit($kits, $knee, 'KN-TIB-4', $staff);
        $this->loadUnit($kits, $knee, 'KN-FEM-4', $staff);
        $this->loadUnit($kits, $knee, 'KN-INS-10', $staff);
        $this->loadLot($kits, $knee, 'SC-COR-3530', 6, $staff);
        $kits->markPrepared($knee, $staff);

        $kits->addInstrument($hip, $reamer, $staff);
        $hipStem = $this->loadUnit($kits, $hip, 'HP-STM-12', $staff);
        $this->loadUnit($kits, $hip, 'HP-CUP-52', $staff);

        $kits->addInstrument($eblKnee, $eblGuide, $admin);

        // Patients (the demo doctor's).
        $people = [
            ['Karwan Hassan', '0750 111 2201', 67, Gender::Male],
            ['Shilan Omar', '0750 111 2202', 59, Gender::Female],
            ['Ahmed Saleh', '0770 111 2203', 72, Gender::Male],
            ['Nazdar Aziz', '0751 111 2204', 48, Gender::Female],
        ];
        $patientIds = [];

        foreach ($people as [$name, $phone, $age, $gender]) {
            $patientIds[] = $patients->create($dhk, ['full_name' => $name, 'phone' => $phone, 'age' => $age, 'gender' => $gender->value, 'primary_doctor_id' => $doctor->id], $admin)->id;
        }

        // A knee replacement last week, from the shelf.
        $shelfUnit = $this->freeUnit($dhk, 'KN-TIB-3') ?? $this->freeUnit($dhk, 'KN-TIB-5');
        $surgeries->sync([
            'client_uuid' => (string) Str::uuid(),
            'branch_id' => $dhk->id,
            'surgery_date' => now()->subDays(6)->toDateString(),
            'surgery_type_id' => $types['TKA']->id,
            'customer_id' => $azadi->id,
            'patient_id' => $patientIds[0],
            'laterality' => 'right',
            'lead_doctor_id' => $doctor->id,
            'notes' => 'Uneventful.',
            'items' => array_values(array_filter([
                $shelfUnit !== null ? ['raw' => $shelfUnit->serial] : null,
                ['raw' => 'SC-COR-3530', 'quantity' => 3],
            ])),
            'complete' => true,
        ], $doctor);

        // The hip kit goes to Rizgary; the stem from the kit is used; the kit is now overdue.
        $checkout = $kits->checkout($hip, $rizgary, $staff, $doctor, plannedDate: now()->subDays(9)->toDateString());
        $checkout->forceFill(['checked_out_at' => now()->subDays(10), 'due_back_at' => now()->subDays(3)])->save();

        if ($hipStem !== null) {
            $surgeries->sync([
                'client_uuid' => (string) Str::uuid(),
                'branch_id' => $dhk->id,
                'surgery_date' => now()->subDays(9)->toDateString(),
                'surgery_type_id' => $types['THA']->id,
                'customer_id' => $rizgary->id,
                'patient_id' => $patientIds[1],
                'laterality' => 'left',
                'kit_checkout_id' => $checkout->id,
                'lead_doctor_id' => $doctor->id,
                'items' => [['raw' => $hipStem->serial]],
                'complete' => true,
            ], $doctor);
        }

        // A draft from the phone that still misses the hospital.
        $surgeries->sync([
            'client_uuid' => (string) Str::uuid(),
            'branch_id' => $dhk->id,
            'surgery_date' => now()->toDateString(),
            'surgery_type_id' => $types['ORIF']->id,
            'patient_id' => $patientIds[2],
            'laterality' => 'left',
            'lead_doctor_id' => $doctor->id,
            'items' => [['raw' => 'SC-CAN-7050', 'quantity' => 2]],
            'complete' => true,
        ], $doctor);
    }

    /** @return array<string, SurgeryType> */
    private function surgeryTypes(): array
    {
        $rows = [
            'TKA' => ['en' => 'Total knee replacement', 'ar' => 'استبدال الركبة الكامل', 'ckb' => 'گۆڕینی تەواوی ئەژنۆ'],
            'THA' => ['en' => 'Total hip replacement', 'ar' => 'استبدال الورك الكامل', 'ckb' => 'گۆڕینی تەواوی سمت'],
            'ORIF' => ['en' => 'Fracture fixation (ORIF)', 'ar' => 'تثبيت الكسر (ORIF)', 'ckb' => 'جێگیرکردنی شکاوی (ORIF)'],
            'SPF' => ['en' => 'Spinal fusion', 'ar' => 'دمج الفقرات', 'ckb' => 'یەکخستنی بڕبڕە'],
        ];
        $types = [];
        $sort = 0;

        foreach ($rows as $code => $name) {
            $types[$code] = SurgeryType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'sort' => $sort++]);
        }

        return $types;
    }

    /** @return array<string, InstrumentType> */
    private function instrumentTypes(): array
    {
        $rows = [
            'GUIDE' => ['en' => 'Cutting guide', 'ar' => 'دليل القطع', 'ckb' => 'ڕێنیشاندەری بڕین'],
            'TRIAL' => ['en' => 'Trial set', 'ar' => 'طقم تجريبي', 'ckb' => 'کۆمەڵەی تاقیکردنەوە'],
            'REAMER' => ['en' => 'Acetabular reamer', 'ar' => 'موسّع الحُق', 'ckb' => 'فراوانکەری سمت'],
            'DRILL' => ['en' => 'Power drill handpiece', 'ar' => 'مقبض المثقاب الكهربائي', 'ckb' => 'دەسکی دریلی کارەبایی'],
        ];
        $types = [];

        foreach ($rows as $code => $name) {
            $types[$code] = InstrumentType::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }

        return $types;
    }

    /**
     * @param  array<string, InstrumentType>  $instrumentTypes
     * @return array<string, KitType>
     */
    private function kitTypes(array $instrumentTypes): array
    {
        $knee = KitType::query()->firstOrCreate(['code' => 'KNEE'], [
            'name' => ['en' => 'Knee replacement kit', 'ar' => 'طقم استبدال الركبة', 'ckb' => 'کیتی گۆڕینی ئەژنۆ'],
            'description' => ['en' => 'Primary TKA, sizes 4'],
        ]);
        $hip = KitType::query()->firstOrCreate(['code' => 'HIP'], [
            'name' => ['en' => 'Hip replacement kit', 'ar' => 'طقم استبدال الورك', 'ckb' => 'کیتی گۆڕینی سمت'],
        ]);

        $product = fn (string $sku) => Product::query()->where('sku', $sku)->value('id');
        $lines = [
            [$knee, ['instrument_type_id' => $instrumentTypes['GUIDE']->id]],
            [$knee, ['instrument_type_id' => $instrumentTypes['TRIAL']->id]],
            [$knee, ['product_id' => $product('KN-TIB-4')]],
            [$knee, ['product_id' => $product('KN-FEM-4')]],
            [$knee, ['product_id' => $product('KN-INS-10')]],
            [$knee, ['product_id' => $product('SC-COR-3530'), 'expected_qty' => 6, 'is_required' => false]],
            [$hip, ['instrument_type_id' => $instrumentTypes['REAMER']->id]],
            [$hip, ['product_id' => $product('HP-STM-12')]],
            [$hip, ['product_id' => $product('HP-CUP-52')]],
            [$hip, ['product_id' => $product('HP-HEAD-28')]],
        ];

        foreach ($lines as $sort => [$type, $line]) {
            if (array_filter($line) !== []) {
                $type->templateItems()->create($line + ['expected_qty' => 1, 'sort' => $sort]);
            }
        }

        return ['KNEE' => $knee, 'HIP' => $hip];
    }

    private function freeUnit(Branch $branch, string $sku): ?StockUnit
    {
        return StockUnit::query()
            ->where('branch_id', $branch->id)
            ->where('status', StockStatus::InStock)
            ->where('product_id', Product::query()->where('sku', $sku)->value('id'))
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', now()->addMonths(3)->toDateString()))
            ->whereDoesntHave('transferItems')
            ->whereDoesntHave('writeOffItems')
            ->orderBy('expiry_date')
            ->first();
    }

    private function loadUnit(KitService $kits, Kit $kit, string $sku, User $by): ?StockUnit
    {
        $unit = $this->freeUnit($kit->branch, $sku);

        if ($unit !== null) {
            $kits->addUnit($kit, $unit, $by);
        }

        return $unit;
    }

    private function loadLot(KitService $kits, Kit $kit, string $sku, int $quantity, User $by): void
    {
        $batch = StockBatch::query()
            ->where('branch_id', $kit->branch_id)
            ->where('location_type', LocationType::Shelf)
            ->where('product_id', Product::query()->where('sku', $sku)->value('id'))
            ->where('quantity', '>=', $quantity)
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>', now()->toDateString()))
            ->orderBy('expiry_date')
            ->first();

        if ($batch !== null) {
            $kits->addBatch($kit, $batch, $quantity, $by);
        }
    }
}
