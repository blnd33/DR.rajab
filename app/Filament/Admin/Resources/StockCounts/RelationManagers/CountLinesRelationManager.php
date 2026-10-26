<?php

namespace App\Filament\Admin\Resources\StockCounts\RelationManagers;

use App\Enums\CountLineResolution;
use App\Enums\CountLineResult;
use App\Enums\StockCountStatus;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Services\Movement\StockCountService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Expected vs counted per line, and the decision for each difference.
 */
class CountLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Lines');
    }

    #[On('count-updated')]
    public function refreshLines(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'stockUnit', 'stockBatch', 'foundBranch']))
            ->defaultSort('id')
            ->columns([
                ProductColumn::make('product'),
                TextColumn::make('code')->label(__('Serial / lot'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])->state(fn (StockCountLine $r) => $r->code()),
                TextColumn::make('expected_qty')->label(__('Expected'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('counted_qty')->label(__('Counted'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('result')->label(__('Result'))->badge()->placeholder('—')
                    ->description(fn (StockCountLine $r) => $r->foundBranch !== null ? __('Belongs to :branch', ['branch' => $r->foundBranch->code]) : $r->note),
                TextColumn::make('resolution')->label(__('Decision'))->badge(),
            ])
            ->filters([
                Filter::make('differences')->label(__('Only differences'))->default()
                    ->query(fn (Builder $query) => $query->where(fn ($w) => $w->whereNull('result')->orWhere('result', '<>', CountLineResult::Found->value))),
                SelectFilter::make('resolution')->label(__('Decision'))->options(CountLineResolution::options()),
            ])
            ->recordActions([
                Action::make('counted')
                    ->label(__('Set counted'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->visible(fn (StockCountLine $r) => $r->stock_batch_id !== null && $this->stockCount()->status === StockCountStatus::InProgress)
                    ->fillForm(fn (StockCountLine $r) => ['counted' => $r->counted_qty])
                    ->schema([TextInput::make('counted')->label(__('Counted'))->numeric()->integer()->minValue(0)->required()])
                    ->action(fn (StockCountLine $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(StockCountService::class)->setCounted($record, (int) $data['counted'], $u))),
                Action::make('decide')
                    ->label(__('Decide'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('warning')
                    ->visible(fn (StockCountLine $r) => $r->resolution === CountLineResolution::Pending
                        && $r->result !== null && $r->result !== CountLineResult::Found
                        && in_array($this->stockCount()->status, [StockCountStatus::Submitted, StockCountStatus::Reviewed], true)
                        && ServiceAction::user()->canInBranch('stock_counts.review', $this->stockCount()->branch_id))
                    ->schema(fn (StockCountLine $r) => [
                        Select::make('resolution')->label(__('What should happen?'))->required()->native(false)
                            ->options(collect(in_array($r->result, [CountLineResult::ExtraOtherBranch, CountLineResult::ExtraUnknown], true)
                                ? [CountLineResolution::Investigating, CountLineResolution::Ignored]
                                : [CountLineResolution::Adjusted, CountLineResolution::WrittenOff, CountLineResolution::Investigating, CountLineResolution::Ignored])
                                ->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])->all())
                            ->helperText(__('Adjust = correct the books to what was counted. Write off = send the missing items for write-off approval.')),
                        Textarea::make('note')->label(__('Note'))->rows(2),
                    ])
                    ->action(function (StockCountLine $record, array $data, Action $action) {
                        ServiceAction::run($action, fn ($u) => app(StockCountService::class)->resolveLine($record, CountLineResolution::from($data['resolution']), $u, $data['note'] ?? null), __('Decision saved.'));
                    }),
            ])
            ->emptyStateHeading(__('No differences'));
    }

    private function stockCount(): StockCount
    {
        /** @var StockCount */
        return $this->getOwnerRecord();
    }
}
