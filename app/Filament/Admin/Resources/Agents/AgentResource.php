<?php

namespace App\Filament\Admin\Resources\Agents;

use App\Enums\AgentType;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\CompanyAgent;
use App\Services\Agents\CompanyAgentService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One list per kind of company agent (Company agents in the side menu). Each subclass names its
 * type; the form, table and page show only that type's information. Saving goes through
 * CompanyAgentService. Agents are never deleted, only made inactive.
 */
abstract class AgentResource extends Resource
{
    protected static ?string $model = CompanyAgent::class;

    protected static ?string $recordTitleAttribute = 'name';

    abstract public static function agentType(): AgentType;

    public static function getNavigationGroup(): ?string
    {
        return __('Company agents');
    }

    public static function getModelLabel(): string
    {
        return static::agentType()->getLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::agentType()->pluralLabel();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', static::agentType()->value);
    }

    public static function table(Table $table): Table
    {
        $type = static::agentType();
        $money = fn (string $prefix) => fn ($state, CompanyAgent $record) => Display::money($state, $record->{"{$prefix}_currency"});

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('cv'))
            ->defaultSort('name')
            ->columns(array_values(array_filter([
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable()->weight('semibold'),
                CodeColumn::make('phone')->label(__('Phone'))->placeholder('—')->searchable(),
                $type->has('city') ? TextColumn::make('city')->label(__('City'))->placeholder('—')->searchable()->sortable() : null,
                $type->has('position') ? TextColumn::make('position')->label(__('Position'))->placeholder('—')->searchable() : null,
                $type->has('user') ? TextColumn::make('user.name')->label(__('System account'))->placeholder('—')->toggleable() : null,
                $type->has('stock') ? TextColumn::make('stock')->label(__('Stock'))->placeholder('—')->limit(40)->toggleable() : null,
                $type->has('shares') ? TextColumn::make('shares_percent')->label(__('Stocks (share %)'))->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state) => Display::number($state, 2).'%') : null,
                $type->has('value') ? TextColumn::make('value_amount')->label(__('Value'))->alignEnd()->placeholder('—')->sortable()->formatStateUsing($money('value')) : null,
                $type->has('profit') ? TextColumn::make('profit_month_amount')->label(__('Profit per month'))->alignEnd()->placeholder('—')->formatStateUsing($money('profit')) : null,
                $type->has('profit') ? TextColumn::make('profit_year_amount')->label(__('Profit per year'))->alignEnd()->placeholder('—')->formatStateUsing($money('profit')) : null,
                $type->has('salary') ? TextColumn::make('salary_amount')->label(__('Salary'))->alignEnd()->placeholder('—')->sortable()->formatStateUsing($money('salary')) : null,
                $type->has('mission_fee') ? TextColumn::make('mission_fee_amount')->label(__('Fee per mission'))->alignEnd()->placeholder('—')->formatStateUsing($money('mission_fee')) : null,
                $type->has('cv') ? IconColumn::make('has_cv')->label(__('CV'))->boolean()->state(fn (CompanyAgent $record) => $record->cv !== null) : null,
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])))
            ->filters([TernaryFilter::make('is_active')->label(__('Active'))->default(true)])
            ->recordActions([ViewAction::make(), static::editAction()])
            ->emptyStateHeading(__('No :agents yet', ['agents' => $type->pluralLabel()]))
            ->emptyStateDescription(__('Only the super admin adds company agents. They do not sign in.'));
    }

    public static function editAction(): Action
    {
        $type = static::agentType();

        return Action::make('edit')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn (CompanyAgent $record) => static::canEdit($record))
            ->modalHeading(fn (CompanyAgent $record) => $record->name)
            ->fillForm(fn (CompanyAgent $record) => AgentForm::fill($record))
            ->schema(AgentForm::components($type, editing: true))
            ->action(function (CompanyAgent $record, array $data, Action $action) {
                ServiceAction::run($action, fn ($u) => app(CompanyAgentService::class)->update($record, $data, $u, AgentForm::cv($data)), __('Saved.'));
                AgentForm::forgetUpload($data);
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema(static::detailEntries(static::agentType())),
        ]);
    }

    /**
     * @return list<mixed>
     */
    public static function detailEntries(AgentType $type): array
    {
        $money = fn (string $prefix) => fn (CompanyAgent $record) => Display::money($record->{"{$prefix}_amount"}, $record->{"{$prefix}_currency"}) ?: null;

        return array_values(array_filter([
            $type !== AgentType::Technician ? TextEntry::make('name')->label(__('Name'))->weight('semibold') : null,
            $type !== AgentType::Technician ? TextEntry::make('phone')->label(__('Phone'))->placeholder('—')->extraAttributes(['dir' => 'ltr'])->fontFamily('mono') : null,
            $type->has('city') ? TextEntry::make('city')->label(__('City'))->placeholder('—') : null,
            $type->has('position') ? TextEntry::make('position')->label(__('Position'))->placeholder('—') : null,
            $type->has('user') ? TextEntry::make('user.name')->label(__('System account'))->placeholder('—') : null,
            $type->has('shares') ? TextEntry::make('shares_percent')->label(__('Stocks (share %)'))->placeholder('—')->formatStateUsing(fn ($state) => Display::number($state, 2).'%') : null,
            $type->has('value') ? TextEntry::make('value')->label(__('Value'))->state($money('value'))->placeholder('—') : null,
            $type->has('profit') ? TextEntry::make('profit_month')->label(__('Profit per month'))->state(fn (CompanyAgent $r) => Display::money($r->profit_month_amount, $r->profit_currency) ?: null)->placeholder('—') : null,
            $type->has('profit') ? TextEntry::make('profit_year')->label(__('Profit per year'))->state(fn (CompanyAgent $r) => Display::money($r->profit_year_amount, $r->profit_currency) ?: null)->placeholder('—') : null,
            $type->has('salary') ? TextEntry::make('salary')->label(__('Salary (per month)'))->state($money('salary'))->placeholder('—') : null,
            $type->has('mission_fee') ? TextEntry::make('mission_fee')->label(__('Fee per mission'))->state($money('mission_fee'))->placeholder('—') : null,
            $type->has('stock') ? TextEntry::make('stock')->label(__('Stock'))->placeholder('—') : null,
            $type->has('persons') ? TextEntry::make('persons')->label(__('Persons'))->placeholder('—')->columnSpanFull() : null,
            $type !== AgentType::Technician ? TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull() : null,
            $type !== AgentType::Technician ? IconEntry::make('is_active')->label(__('Active'))->boolean() : null,
            $type->has('cv') ? ViewEntry::make('cvs')->label(__('CV'))->view('filament.agents.cvs')->columnSpanFull() : null,
        ]));
    }
}
