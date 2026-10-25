<?php

namespace App\Filament\Admin\Resources\Branches\Tables;

use App\Enums\BranchStatus;
use App\Exceptions\Domain\BranchClosureBlocked;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Branch;
use App\Models\User;
use App\Services\Branches\BranchService;
use App\Support\Database\Pg;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class BranchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('users')->with('manager'))
            ->defaultSort('code')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->sortable()->searchable(),
                TextColumn::make('name')->label(__('Name'))->searchable(query: fn ($query, string $search) => $query->whereRaw(Pg::jsonContainsSql('name'), ["%{$search}%"])),
                TextColumn::make('city')->label(__('City'))->toggleable(),
                TextColumn::make('manager.name')->label(__('Manager'))->toggleable(),
                TextColumn::make('default_currency')->label(__('Currency'))->badge()->color('gray'),
                TextColumn::make('users_count')->label(__('Users'))->numeric()->alignEnd(),
                // Only the head office gets a mark; "no" is not an error, so nothing is shown.
                IconColumn::make('is_head_office')
                    ->label(__('Head office'))
                    ->icon(fn (bool $state) => $state ? Heroicon::Star : null)
                    ->color('warning')
                    ->tooltip(fn (bool $state) => $state ? __('Head office') : null),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(BranchStatus::options())
                    ->default(BranchStatus::Active->value),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    self::closeAction(),
                    self::reopenAction(),
                    self::deleteAction(),
                ]),
            ])
            ->emptyStateHeading(__('No branches yet'))
            ->emptyStateDescription(__('Add your first branch to start.'));
    }

    private static function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('Close branch'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('danger')
            ->visible(fn (Branch $record) => Auth::user()?->can('close', $record))
            ->modalHeading(fn (Branch $record) => __('Close branch :code?', ['code' => $record->code]))
            ->modalDescription(fn (Branch $record) => self::closeDescription($record))
            ->schema([
                Textarea::make('reason')->label(__('Reason'))->required()->rows(2),
            ])
            ->action(function (Branch $record, array $data, Action $action) {
                /** @var User $user */
                $user = Auth::user();

                try {
                    app(BranchService::class)->close($record, $data['reason'], $user);
                } catch (BranchClosureBlocked $e) {
                    Notification::make()
                        ->title($e->getMessage())
                        ->body(implode("\n", $e->reasons))
                        ->danger()
                        ->persistent()
                        ->send();

                    $action->halt();
                }

                Notification::make()->title(__('Branch closed. Its history stays in all reports.'))->success()->send();
            });
    }

    private static function closeDescription(Branch $record): HtmlString|string
    {
        $blockers = app(BranchService::class)->closureBlockers($record);

        if ($blockers === []) {
            return __('Closed branches disappear from daily lists but keep all their history. You can reopen it later.');
        }

        $items = collect($blockers)->map(fn ($b) => '<li>'.e($b).'</li>')->implode('');

        return new HtmlString('<p>'.e(__('Before closing, finish these:')).'</p><ul class="list-disc ps-5 mt-2">'.$items.'</ul>');
    }

    private static function reopenAction(): Action
    {
        return Action::make('reopen')
            ->label(__('Reopen branch'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->visible(fn (Branch $record) => Auth::user()?->can('reopen', $record))
            ->requiresConfirmation()
            ->action(function (Branch $record) {
                /** @var User $user */
                $user = Auth::user();
                app(BranchService::class)->reopen($record, $user);

                Notification::make()->title(__('Branch reopened.'))->success()->send();
            });
    }

    private static function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('Delete (created by mistake)'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (Branch $record) => Auth::user()?->can('delete', $record) && app(BranchService::class)->canHardDelete($record))
            ->requiresConfirmation()
            ->modalDescription(__('This branch has no records, so it can be removed completely. This cannot be undone.'))
            ->action(function (Branch $record) {
                app(BranchService::class)->hardDelete($record);

                Notification::make()->title(__('Branch deleted.'))->success()->send();
            });
    }
}
