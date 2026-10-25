<?php

namespace App\Filament\Admin\Actions;

use App\Http\Controllers\LabelPrintController;
use App\Models\LabelTemplate;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * "Print labels" for products, serial units and lots: pick a label template and copies,
 * the print sheet opens in a new tab (browser print → thermal printer).
 */
final class PrintLabelsAction
{
    /**
     * @param  'product'|'unit'|'batch'  $kind
     */
    public static function make(string $kind): Action
    {
        return Action::make('printLabels')
            ->label(__('Print labels'))
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->schema(self::schema())
            ->modalSubmitActionLabel(__('Open print sheet'))
            ->action(function (Model $record, array $data, Component $livewire) use ($kind) {
                $livewire->js('window.open('.json_encode(LabelPrintController::url($kind, [$record->getKey()], (int) $data['template'], (int) $data['copies'])).', "_blank")');
            });
    }

    /**
     * @param  'product'|'unit'|'batch'  $kind
     */
    public static function bulk(string $kind): BulkAction
    {
        return BulkAction::make('printLabels')
            ->label(__('Print labels'))
            ->icon(Heroicon::OutlinedPrinter)
            ->schema(self::schema())
            ->modalSubmitActionLabel(__('Open print sheet'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data, Component $livewire) use ($kind) {
                $url = LabelPrintController::url($kind, $records->modelKeys(), (int) $data['template'], (int) $data['copies']);
                $livewire->js('window.open('.json_encode($url).', "_blank")');
            });
    }

    /**
     * @return list<Field>
     */
    private static function schema(): array
    {
        return [
            Select::make('template')
                ->label(__('Label template'))
                ->options(fn () => LabelTemplate::query()->orderByDesc('is_default')->orderBy('name')->get()
                    ->mapWithKeys(fn (LabelTemplate $t) => [$t->id => "{$t->name} · {$t->sizeLabel()} · {$t->symbology->getLabel()}"]))
                ->default(fn () => LabelTemplate::default()?->id)
                ->required()
                ->native(false),
            TextInput::make('copies')
                ->label(__('Copies of each label'))
                ->numeric()
                ->minValue(1)
                ->maxValue(50)
                ->default(1)
                ->required(),
        ];
    }
}
