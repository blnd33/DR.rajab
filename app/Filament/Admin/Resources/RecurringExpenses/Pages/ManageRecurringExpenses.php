<?php

namespace App\Filament\Admin\Resources\RecurringExpenses\Pages;

use App\Filament\Admin\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Services\Expenses\RecurringExpenseService;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageRecurringExpenses extends ManageRecords
{
    protected static string $resource = RecurringExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label(__('Create what is due now'))
                ->icon(Heroicon::OutlinedPlay)
                ->color('gray')
                ->visible(fn () => app(BranchScopeResolver::class)->hasAny(ServiceAction::user(), 'expenses.recurring'))
                ->requiresConfirmation()
                ->modalDescription(__('This also runs every morning by itself.'))
                ->action(function () {
                    $count = app(RecurringExpenseService::class)->generateDue();
                    Notification::make()->title(__(':count expenses created.', ['count' => Display::number($count)]))->success()->send();
                }),
            CreateAction::make()->mutateDataUsing(fn (array $data) => RecurringExpenseResource::withCurrency($data) + ['created_by' => ServiceAction::user()->id]),
        ];
    }
}
