<?php

namespace App\Filament\Admin\Resources\DataImports\Pages;

use App\Enums\ImportStatus;
use App\Filament\Admin\Resources\DataImports\DataImportResource;
use App\Models\DataImport;
use App\Models\User;
use App\Services\Imports\ImportPipeline;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Steps 2–4: preview (counts + every row with old → new), confirm, progress, error file.
 */
class ViewDataImport extends ViewRecord
{
    protected static string $resource = DataImportResource::class;

    public function getTitle(): string
    {
        /** @var DataImport $record */
        $record = $this->getRecord();

        return $record->type->getLabel().' · '.$record->original_filename;
    }

    /**
     * Re-read the import while it is being checked or written (the summary polls).
     */
    public function refreshImport(): void
    {
        $this->getRecord()->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')
                ->label(fn () => __('Import :count rows', ['count' => Display::number($this->import()->applicableCount())]))
                ->icon(Heroicon::OutlinedCheck)
                ->color('success')
                ->visible(fn () => $this->import()->status === ImportStatus::Previewed && $this->import()->applicableCount() > 0)
                ->requiresConfirmation()
                ->modalHeading(__('Import the checked rows?'))
                ->modalDescription(fn () => $this->import()->error_count > 0
                    ? __(':errors rows with errors are skipped and listed in the error file. The other rows are written now.', ['errors' => Display::number($this->import()->error_count)])
                    : __('All rows passed the check and will be written now.'))
                ->action(function () {
                    try {
                        app(ImportPipeline::class)->confirm($this->import(), $this->user());
                    } catch (ValidationException $e) {
                        Notification::make()->title(implode(' ', array_merge(...array_values($e->errors()))))->danger()->send();

                        return;
                    }

                    $this->refreshImport();
                    Notification::make()->title(__('Import started. You can leave this page — it continues in the background.'))->success()->send();
                }),
            Action::make('cancel')
                ->label(__('Cancel import'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('gray')
                ->visible(fn () => in_array($this->import()->status, [ImportStatus::Uploaded, ImportStatus::Previewed, ImportStatus::Failed], true))
                ->requiresConfirmation()
                ->action(function () {
                    app(ImportPipeline::class)->cancel($this->import(), $this->user());
                    $this->refreshImport();
                }),
            Action::make('errors')
                ->label(__('Download error file'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->visible(fn () => $this->import()->error_file_path !== null)
                ->url(fn () => route('imports.errors', $this->import())),
            Action::make('original')
                ->label(__('Original file'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->url(fn () => route('imports.original', $this->import())),
        ];
    }

    private function import(): DataImport
    {
        /** @var DataImport */
        return $this->getRecord();
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
