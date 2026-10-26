<?php

namespace App\Filament\Admin\Resources\CashBoxes\Pages;

use App\Filament\Admin\Resources\CashBoxClosings\CashBoxClosingResource;
use App\Filament\Admin\Resources\CashBoxes\CashBoxResource;
use App\Filament\Admin\Support\RefreshesInfolist;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\CashBox;
use App\Services\Money\BoxTransferService;
use App\Services\Money\CashBoxService;
use App\Services\Money\CashClosingService;
use App\Services\Money\CurrencyExchangeService;
use App\Services\Money\FxService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ViewCashBox extends ViewRecord
{
    use RefreshesInfolist;

    protected static string $resource = CashBoxResource::class;

    public function getTitle(): string
    {
        return $this->box()->label();
    }

    protected function getHeaderActions(): array
    {
        $user = ServiceAction::user();
        $boxes = app(CashBoxService::class);

        return [
            Action::make('close_day')
                ->label(__('Close the day'))
                ->icon(Heroicon::OutlinedScale)
                ->visible(fn () => $this->box()->is_active && $user->canInBranch('cash_boxes.close_day', $this->box()->branch_id))
                ->action(function (Action $action) {
                    $closing = ServiceAction::run($action, fn ($u) => app(CashClosingService::class)->start($this->box(), $u));

                    if ($closing !== null) {
                        $this->redirect(CashBoxClosingResource::getUrl('index', ['tableAction' => 'count', 'tableActionRecord' => $closing->id]));
                    }
                }),
            Action::make('transfer')
                ->label(__('Move money'))
                ->icon(Heroicon::OutlinedArrowRight)
                ->color('gray')
                ->visible(fn () => $this->box()->is_active && $user->canInBranch('cash_boxes.transfer', $this->box()->branch_id))
                ->schema([
                    Select::make('to_cash_box_id')->label(__('To box'))->required()->native(false)
                        ->options(fn () => collect($boxes->options($user, 'cash_boxes.view_any', $this->box()->currency))->except([$this->box()->id])->all()),
                    TextInput::make('amount')->label(__('Amount (:currency)', ['currency' => $this->box()->currency]))->numeric()->minValue(0)->required(),
                    Toggle::make('receive_now')->label(__('Received at once (same place)'))->default(true),
                    Textarea::make('note')->label(__('Note'))->rows(2),
                ])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(BoxTransferService::class)->send($this->box(), CashBox::query()->findOrFail($data['to_cash_box_id']), (string) $data['amount'], $u, $data['note'] ?? null, (bool) ($data['receive_now'] ?? false)), __('Money moved.'));
                    $this->refreshBox();
                }),
            Action::make('exchange')
                ->label(__('Exchange currency'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->visible(fn () => $this->box()->is_active && $user->canInBranch('currency_exchange.create', $this->box()->branch_id))
                ->schema([
                    Select::make('to_cash_box_id')->label(__('Into box'))->required()->native(false)->live()
                        ->options(fn () => collect($boxes->options($user, 'cash_boxes.view_any', branchId: $this->box()->branch_id))
                            ->filter(fn ($label, $id) => CashBox::query()->whereKey($id)->value('currency') !== $this->box()->currency)->all()),
                    TextInput::make('amount')->label(__('Amount given (:currency)', ['currency' => $this->box()->currency]))->numeric()->minValue(0)->required(),
                    TextInput::make('rate')->label(__('Deal rate'))->numeric()->minValue(0)->required()
                        ->helperText(fn (Get $get) => $this->rateHint($get('to_cash_box_id'))),
                    Textarea::make('note')->label(__('Note'))->rows(2),
                ])
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(CurrencyExchangeService::class)->exchange($this->box(), CashBox::query()->findOrFail($data['to_cash_box_id']), (string) $data['amount'], (string) $data['rate'], $u, $data['note'] ?? null), __('Exchanged.'));
                    $this->refreshBox();
                }),
            ActionGroup::make([
                Action::make('edit')
                    ->label(__('Edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn () => $user->canInBranch('cash_boxes.manage', $this->box()->branch_id))
                    ->fillForm(fn () => ['bank_name' => $this->box()->bank_name, 'account_number' => $this->box()->account_number, 'iban' => $this->box()->iban])
                    ->schema([
                        TextInput::make('bank_name')->label(__('Bank'))->maxLength(120),
                        TextInput::make('account_number')->label(__('Account number'))->maxLength(64),
                        TextInput::make('iban')->label(__('IBAN'))->maxLength(64),
                    ])
                    ->action(fn (array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(CashBoxService::class)->update($this->box(), $data, $u), __('Saved.'))),
                Action::make('close_box')
                    ->label(__('Close this box'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('danger')
                    ->visible(fn () => $this->box()->is_active && $user->canInBranch('cash_boxes.manage', $this->box()->branch_id))
                    ->requiresConfirmation()
                    ->action(function (Action $action) {
                        ServiceAction::run($action, fn ($u) => app(CashBoxService::class)->close($this->box(), $u), __('Box closed.'));
                        $this->refreshBox();
                    }),
            ])->button()->color('gray')->label(__('More')),
        ];
    }

    private function rateHint(mixed $toBoxId): ?string
    {
        $to = filled($toBoxId) ? CashBox::query()->find($toBoxId) : null;
        $snapshot = app(FxService::class)->current();

        if ($to === null || $snapshot === null) {
            return null;
        }

        return __('1 :from = … :to (reference: :rate)', [
            'from' => $this->box()->currency,
            'to' => $to->currency,
            'rate' => Display::number(app(FxService::class)->converter($snapshot)->rate($this->box()->currency, $to->currency), 4),
        ]);
    }

    public function refreshBox(): void
    {
        $this->record = $this->box()->fresh();
        $this->dispatch('box-updated');
    }

    private function box(): CashBox
    {
        /** @var CashBox */
        return $this->getRecord();
    }
}
