<?php

namespace App\Filament\Admin\Resources\Branches\RelationManagers;

use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Branch;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

/**
 * Shelves / storage spots of a branch ("A-1", "Fridge"). Deactivated, never deleted.
 */
class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Shelves');
    }

    public function isReadOnly(): bool
    {
        return ! (Auth::user()?->can('branches.update') ?? false);
    }

    public function form(Schema $schema): Schema
    {
        /** @var Branch $branch */
        $branch = $this->getOwnerRecord();

        return $schema->columns(2)->components([
            TextInput::make('code')
                ->label(__('Code'))
                ->required()
                ->maxLength(32)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('branch_id', $branch->id))
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            Toggle::make('is_active')->label(__('Active'))->default(true)->inline(false),
            TranslatableInput::make('name', __('Name'), required: false)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                CodeColumn::make('code')->label(__('Code'))->sortable(),
                TextColumn::make('name')->label(__('Name'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->headerActions([CreateAction::make()->label(__('Add shelf'))])
            ->recordActions([EditAction::make()])
            ->emptyStateHeading(__('No shelves yet'))
            ->emptyStateDescription(__('Optional: add shelves to record where each item is stored.'));
    }
}
