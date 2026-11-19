<?php

namespace App\Filament\Admin\Resources\StockCounts\Pages;

use App\Enums\StockCountScope;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\BranchLocation;
use App\Models\Category;
use App\Services\Movement\StockCountService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateStockCount extends CreateRecord
{
    protected static string $resource = StockCountResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('branch_id')->label(__('Station'))->options(fn () => ServiceAction::branchOptions('stock_counts.start'))->required()->live()->native(false),
            ToggleButtons::make('scope')->label(__('What do you count?'))->options(StockCountScope::options())->default(StockCountScope::Full->value)->inline()->live()->required(),
            Select::make('category_id')->label(__('Category'))->options(fn () => Category::options())->searchable()->native(false)
                ->visible(fn (Get $get) => $get('scope') === StockCountScope::Category->value)->required(fn (Get $get) => $get('scope') === StockCountScope::Category->value),
            Select::make('branch_location_id')->label(__('Shelf'))
                ->options(fn (Get $get) => BranchLocation::query()->active()->where('branch_id', (int) $get('branch_id'))->orderBy('code')->get()->mapWithKeys(fn ($l) => [$l->id => $l->label()]))
                ->native(false)
                ->visible(fn (Get $get) => $get('scope') === StockCountScope::Location->value)->required(fn (Get $get) => $get('scope') === StockCountScope::Location->value),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(StockCountService::class)->start(
                Branch::query()->findOrFail($data['branch_id']),
                StockCountScope::from($data['scope']),
                ServiceAction::user(),
                filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null,
                filled($data['branch_location_id'] ?? null) ? (int) $data['branch_location_id'] : null,
                $data['notes'] ?? null,
            );
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.scope' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return StockCountResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
