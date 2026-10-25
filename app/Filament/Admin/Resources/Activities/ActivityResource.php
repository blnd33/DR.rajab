<?php

namespace App\Filament\Admin\Resources\Activities;

use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\Activities\Pages\ListActivities;
use App\Filament\Admin\Resources\Activities\Pages\ViewActivity;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\Activity;
use App\Models\Branch;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only audit log (no create/edit/delete for anyone).
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getModelLabel(): string
    {
        return __('Activity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Activity log');
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Auth::user();
        $ids = app(BranchScopeResolver::class)->branchIdsFor($user, 'activity.view_any');

        $query = parent::getEloquentQuery()->with(['causer', 'branch']);

        if ($ids === null) {
            return $query;
        }

        // Branch-scoped users see their branches plus entries with no branch (e.g. their own logins).
        return $query->where(fn (Builder $q) => $q->whereIn('branch_id', $ids ?: [0])->orWhereNull('branch_id'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                LocalDateTimeColumn::make('created_at')->label(__('When'))->sortable(),
                TextColumn::make('causer.name')->label(__('User'))->placeholder(__('System'))->searchable(),
                TextColumn::make('event')->label(__('Action'))->badge()
                    ->formatStateUsing(fn (?string $state) => $state === null ? '' : __(str($state)->replace('_', ' ')->ucfirst()->toString()))
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted', 'deactivated', 'login_failed', 'login_throttled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('description')->label(__('Description'))->wrap()->searchable(),
                TextColumn::make('subject_type')->label(__('Record'))
                    ->formatStateUsing(fn (?string $state, Activity $record) => $state === null ? '' : class_basename($state).' #'.$record->subject_id)
                    ->toggleable(),
                CodeColumn::make('reference_code')->label(__('Reference'))->searchable()->placeholder('—'),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray')->placeholder('—'),
                CodeColumn::make('ip_address')->label(__('IP address'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('causer_id')
                    ->label(__('User'))
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $data['value'])
                        : $query),
                SelectFilter::make('branch_id')
                    ->label(__('Branch'))
                    ->options(fn () => Branch::query()->orderBy('code')->pluck('code', 'id')),
                SelectFilter::make('log_name')
                    ->label(__('Area'))
                    ->options(fn () => Activity::query()->distinct()->whereNotNull('log_name')->orderBy('log_name')->pluck('log_name', 'log_name')),
                SelectFilter::make('event')
                    ->label(__('Action'))
                    ->options(fn () => Activity::query()->distinct()->whereNotNull('event')->orderBy('event')->pluck('event', 'event')),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label(__('From'))->native(false)->displayFormat('Y-m-d'),
                        DatePicker::make('until')->label(__('Until'))->native(false)->displayFormat('Y-m-d'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading(__('Nothing logged yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        LocalDateTimeEntry::make('created_at')->label(__('When')),
                        TextEntry::make('causer.name')->label(__('User'))->placeholder(__('System')),
                        TextEntry::make('event')->label(__('Action'))->badge(),
                        TextEntry::make('description')->label(__('Description'))->columnSpanFull(),
                        TextEntry::make('subject_type')->label(__('Record'))
                            ->formatStateUsing(fn (?string $state, Activity $record) => $state === null ? '—' : class_basename($state).' #'.$record->subject_id),
                        TextEntry::make('reference_code')->label(__('Reference'))->fontFamily(FontFamily::Mono)->placeholder('—'),
                        TextEntry::make('branch.code')->label(__('Branch'))->placeholder('—'),
                        TextEntry::make('ip_address')->label(__('IP address'))->fontFamily(FontFamily::Mono)->placeholder('—'),
                        TextEntry::make('user_agent')->label(__('Device'))->columnSpan(2)->placeholder('—'),
                    ]),
                Section::make(__('Changes'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->visible(fn (Activity $record) => filled($record->properties?->get('old')) || filled($record->properties?->get('attributes')))
                    ->schema([
                        KeyValueEntry::make('before')->label(__('Before'))->keyLabel(__('Field'))->valueLabel(__('Value'))
                            ->state(fn (Activity $record) => self::stringify($record->properties?->get('old'))),
                        KeyValueEntry::make('after')->label(__('After'))->keyLabel(__('Field'))->valueLabel(__('Value'))
                            ->state(fn (Activity $record) => self::stringify($record->properties?->get('attributes'))),
                    ]),
                Section::make(__('Details'))
                    ->visible(fn (Activity $record) => filled($record->properties?->except(['old', 'attributes'])->all()))
                    ->schema([
                        KeyValueEntry::make('details')
                            ->hiddenLabel()
                            ->state(fn (Activity $record) => self::stringify($record->properties?->except(['old', 'attributes'])->all())),
                    ]),
            ]);
    }

    /**
     * Key/value display needs strings; nested values (translations, lists) become JSON.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, string>
     */
    private static function stringify(?array $values): array
    {
        return collect($values ?? [])
            ->map(fn ($value) => match (true) {
                $value === null => '—',
                is_bool($value) => $value ? __('Yes') : __('No'),
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_UNICODE),
            })
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }
}
