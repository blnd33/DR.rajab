<?php

namespace App\Filament\Admin\Resources\Transfers\Pages;

use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Transfers\TransferResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Services\Movement\TransferService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Start a draft transfer; items are added on the next page (or by scanning on the phone).
 */
class CreateTransfer extends CreateRecord
{
    protected static string $resource = TransferResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('from_branch_id')->label(__('From'))->options(fn () => ServiceAction::branchOptions('transfers.create'))->required()->live()->native(false),
            Select::make('to_branch_id')->label(__('To'))
                ->options(fn ($get) => Branch::query()->active()->where('id', '<>', (int) $get('from_branch_id'))->orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => $b->label()]))
                ->required()->native(false),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(TransferService::class)->createDraft(
                Branch::query()->findOrFail($data['from_branch_id']),
                Branch::query()->findOrFail($data['to_branch_id']),
                ServiceAction::user(),
                $data['notes'] ?? null,
            );
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.to_branch_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return TransferResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
