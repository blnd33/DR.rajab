<?php

namespace App\Filament\Admin\Resources\Patients\Pages;

use App\Filament\Admin\Resources\Patients\PatientResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Patient;
use App\Services\Clinical\PatientService;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPatient extends ViewRecord
{
    protected static string $resource = PatientResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        /** @var Patient $patient */
        $patient = $this->getRecord();
        app(PatientService::class)->logView($patient, ServiceAction::user());
    }

    public function getTitle(): string
    {
        /** @var Patient $patient */
        $patient = $this->getRecord();

        return $patient->full_name;
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
