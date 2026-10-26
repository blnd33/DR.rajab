<?php

namespace App\Filament\Admin\Resources\Patients\Pages;

use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Patients\PatientResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Services\Clinical\PatientService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePatient extends CreateRecord
{
    protected static string $resource = PatientResource::class;

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PatientService::class)->create(Branch::query()->findOrFail($data['branch_id']), $data, ServiceAction::user());
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'full_name') => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return PatientResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
