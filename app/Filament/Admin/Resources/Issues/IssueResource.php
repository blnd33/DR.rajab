<?php

namespace App\Filament\Admin\Resources\Issues;

use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\IssueType;
use App\Filament\Admin\Resources\Issues\Pages\ManageIssues;
use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Filament\Admin\Resources\Transfers\TransferResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\Issue;
use App\Models\StockCount;
use App\Models\Transfer;
use App\Services\Issues\IssueService;
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
 * Issues inbox: differences and exceptions the Super Admin must decide on.
 */
class IssueResource extends Resource
{
    protected static ?string $model = Issue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return __('Operations');
    }

    public static function getModelLabel(): string
    {
        return __('Issue');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Issues');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'issues.view_any', ['branch_id']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', IssueStatus::Open)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function subjectUrl(Issue $issue): ?string
    {
        $subject = $issue->subject;

        return match (true) {
            $subject instanceof Transfer => TransferResource::getUrl('view', ['record' => $subject]),
            $subject instanceof StockCount => StockCountResource::getUrl('view', ['record' => $subject]),
            default => null,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch', 'subject', 'resolver']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('severity')->label(__('Severity'))->badge(),
                TextColumn::make('title')->label(__('Issue'))->wrap()->searchable()
                    ->description(fn (Issue $r) => $r->type->getLabel())
                    ->url(fn (Issue $r) => static::subjectUrl($r)),
                TextColumn::make('branch.code')->label(__('Station'))->badge()->color('gray')->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->description(fn (Issue $r) => $r->resolution_note),
                TextColumn::make('resolver.name')->label(__('Closed by'))->placeholder('—')->toggleable(),
                LocalDateTimeColumn::make('created_at')->label(__('Opened'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(IssueStatus::options())->default(IssueStatus::Open->value),
                SelectFilter::make('type')->label(__('Type'))->options(IssueType::options()),
                SelectFilter::make('severity')->label(__('Severity'))->options(IssueSeverity::options()),
            ])
            ->recordActions([
                Action::make('open_subject')
                    ->label(__('Open'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (Issue $r) => static::subjectUrl($r) !== null)
                    ->url(fn (Issue $r) => static::subjectUrl($r)),
                Action::make('resolve')
                    ->label(__('Resolve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (Issue $r) => $r->status === IssueStatus::Open && ServiceAction::user()->can('issues.resolve'))
                    ->schema([Textarea::make('note')->label(__('What was done?'))->required()->rows(2)])
                    ->action(fn (Issue $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(IssueService::class)->resolve($record, $data['note'], $u), __('Issue resolved.'))),
                Action::make('dismiss')
                    ->label(__('Dismiss'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (Issue $r) => $r->status === IssueStatus::Open && ServiceAction::user()->can('issues.resolve'))
                    ->schema([Textarea::make('note')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (Issue $record, array $data, Action $action) => ServiceAction::run($action, fn ($u) => app(IssueService::class)->dismiss($record, $data['note'], $u), __('Issue dismissed.'))),
            ])
            ->emptyStateHeading(__('No open issues'))
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle);
    }

    public static function getPages(): array
    {
        return ['index' => ManageIssues::route('/')];
    }
}
