<?php

namespace App\Filament\Admin\Resources\Patients\Pages;

use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Patients\PatientResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Patient;
use App\Services\Clinical\PatientService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPatient extends EditRecord
{
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Patient $patient */
        $patient = $this->getRecord();
        $data['phone'] = $patient->phone;
        $data['notes'] = $patient->notes;

        return $data;
    }

    protected static string $resource = PatientResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Patient $record */
        try {
            return app(PatientService::class)->update($record, $data, ServiceAction::user());
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'full_name') => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return PatientResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
