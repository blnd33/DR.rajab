<?php

namespace App\Filament\Admin\Resources\WriteOffs\Pages;

use App\Enums\LocationType;
use App\Enums\WriteOffReason;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\WriteOffs\WriteOffResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Services\Movement\WriteOffService;
use App\Support\Locale\Display;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CreateWriteOff extends CreateRecord
{
    protected static string $resource = WriteOffResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('write_offs.report'))->required()->live()->native(false),
            ToggleButtons::make('reason')->label(__('Reason'))->options(WriteOffReason::options())->default(WriteOffReason::Damaged->value)->inline()->required(),
            Repeater::make('lines')
                ->label(__('Items'))
                ->columnSpanFull()
                ->columns(3)
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel(__('Add an item'))
                ->schema([
                    TextInput::make('serial')->label(__('Serial (for serial units)'))
                        ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true]),
                    Select::make('batch_id')->label(__('or lot'))
                        ->options(fn (Get $get) => StockBatch::query()->with('product')
                            ->where('branch_id', (int) $get('../../branch_id'))->where('location_type', LocationType::Shelf)->where('quantity', '>', 0)
                            ->orderBy('product_id')->get()
                            ->mapWithKeys(fn (StockBatch $b) => [$b->id => $b->product->sku.' · '.$b->codeLabel().' · '.Display::date($b->expiry_date).' · '.__(':qty on shelf', ['qty' => Display::number($b->quantity)])]))
                        ->searchable()->native(false),
                    TextInput::make('quantity')->label(__('Quantity (lots)'))->numeric()->integer()->minValue(1)->default(1),
                ]),
            Textarea::make('note')->label(__('What happened?'))->rows(3)->columnSpanFull(),
            FileUpload::make('photos')->label(__('Photos'))->image()->multiple()->disk('local')->directory('tmp/write-offs')->maxSize(8192)->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $items = [];

        foreach ($data['lines'] ?? [] as $line) {
            if (filled($line['serial'] ?? null)) {
                $unit = StockUnit::query()->where('serial', trim($line['serial']))->first();

                if ($unit === null) {
                    throw ValidationException::withMessages(['data.lines' => __('Serial :serial is not in the system.', ['serial' => $line['serial']])]);
                }

                $items[] = ['stock_unit_id' => $unit->id];
            } elseif (filled($line['batch_id'] ?? null)) {
                $items[] = ['stock_batch_id' => (int) $line['batch_id'], 'quantity' => (int) ($line['quantity'] ?? 1)];
            }
        }

        $photos = array_map(
            fn (string $path) => new UploadedFile(Storage::disk('local')->path($path), basename($path), Storage::disk('local')->mimeType($path) ?: null, null, true),
            array_values((array) ($data['photos'] ?? [])),
        );

        try {
            return app(WriteOffService::class)->report($branch, WriteOffReason::from($data['reason']), $items, ServiceAction::user(), $data['note'] ?? null, $photos);
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.lines' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return WriteOffResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
