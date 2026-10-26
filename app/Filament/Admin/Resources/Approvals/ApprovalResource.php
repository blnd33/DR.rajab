<?php

namespace App\Filament\Admin\Resources\Approvals;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Filament\Admin\Resources\Approvals\Pages\ManageApprovals;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\Approval;
use App\Models\Expense;
use App\Models\SupplierPayment;
use App\Services\Money\ApprovalService;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * One approvals queue (P4-11): large supplier payments, large expenses, commission payouts.
 */
class ApprovalResource extends Resource
{
    protected static ?string $model = Approval::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Approval');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Approvals');
    }

    /**
     * What the user asked for, plus what they may decide (per type, in their branches).
     */
    public static function getEloquentQuery(): Builder
    {
        $user = ServiceAction::user();
        $scope = app(BranchScopeResolver::class);

        return parent::getEloquentQuery()->where(function (Builder $q) use ($user, $scope) {
            $q->where('requested_by', $user->id);

            foreach (ApprovalType::cases() as $type) {
                $permission = ApprovalService::permission($type);

                if (! $scope->hasAny($user, $permission)) {
                    continue;
                }

                $ids = $scope->branchIdsFor($user, $permission);
                $q->orWhere(function (Builder $t) use ($type, $ids) {
                    $t->where('type', $type);

                    if ($ids !== null) {
                        $t->whereIn('branch_id', $ids === [] ? [0] : $ids);
                    }
                });
            }
        });
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', ApprovalStatus::Pending)->where('requested_by', '<>', ServiceAction::user()->id)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canDecide(Approval $approval): bool
    {
        $user = ServiceAction::user();

        return $approval->status === ApprovalStatus::Pending
            && ($approval->requested_by !== $user->id || $user->isSuperAdmin())
            && app(BranchScopeResolver::class)->allows($user, ApprovalService::permission($approval->type), $approval->branch_id);
    }

    public static function describe(Approval $approval): string
    {
        $doc = $approval->approvable;

        return match (true) {
            $doc instanceof SupplierPayment => trim(($doc->number ?? '').' · '.($doc->supplier->name ?? '')),
            $doc instanceof Expense => trim(($doc->number ?? '').' · '.($doc->category->name ?? '').' · '.($doc->description ?? '')),
            default => (string) ($doc->number ?? '—'),
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['approvable', 'branch', 'requester', 'decider']))
            ->defaultSort('requested_at', 'desc')
            ->columns([
                TextColumn::make('type')->label(__('What'))->badge()->description(fn (Approval $r) => static::describe($r)),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state, Approval $r) => Display::money($state, (string) $r->currency))
                    ->description(fn (Approval $r) => $r->currency !== 'USD' ? Display::money($r->amount_usd, 'USD') : null),
                TextColumn::make('branch.name')->label(__('Branch'))->placeholder(__('Head office')),
                TextColumn::make('requester.name')->label(__('Asked by'))->description(fn (Approval $r) => Display::dateTime($r->requested_at)),
                TextColumn::make('status')->label(__('Status'))->badge()->description(fn (Approval $r) => $r->decision_note),
                TextColumn::make('decider.name')->label(__('Decided by'))->placeholder('—')->toggleable(),
                LocalDateTimeColumn::make('decided_at')->label(__('Decided'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(ApprovalStatus::options())->default(ApprovalStatus::Pending->value),
                SelectFilter::make('type')->label(__('What'))->options(ApprovalType::options()),
            ])
            ->recordActions([
                Action::make('approve')->label(__('Approve'))->icon(Heroicon::OutlinedCheck)->color('success')
                    ->visible(fn (Approval $r) => static::canDecide($r))
                    ->schema([Textarea::make('note')->label(__('Note'))->rows(2)])
                    ->action(fn (Approval $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(ApprovalService::class)->approve($record, $u, $data['note'] ?? null), __('Approved.'))),
                Action::make('reject')->label(__('Reject'))->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->visible(fn (Approval $r) => static::canDecide($r))
                    ->schema([Textarea::make('note')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (Approval $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(ApprovalService::class)->reject($record, $u, $data['note']), __('Rejected.'))),
            ])
            ->emptyStateHeading(__('Nothing waiting'))
            ->emptyStateDescription(__('Large supplier payments and expenses wait here for an approver.'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageApprovals::route('/')];
    }
}
