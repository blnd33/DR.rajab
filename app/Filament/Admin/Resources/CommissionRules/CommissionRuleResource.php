<?php

namespace App\Filament\Admin\Resources\CommissionRules;

use App\Enums\CommissionBasis;
use App\Enums\CommissionCalc;
use App\Filament\Admin\Resources\CommissionRules\Pages\ManageCommissionRules;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\Currency;
use App\Models\Product;
use App\Models\SurgeryType;
use App\Models\User;
use App\Services\Expenses\CommissionService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Doctor commission rules (Module 22, P4-15; behind the features.commissions switch).
 */
class CommissionRuleResource extends Resource
{
    protected static ?string $model = CommissionRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 72;

    /**
     * Behind the features.commissions switch, for super admins too.
     */
    public static function canAccess(): bool
    {
        return app(CommissionService::class)->enabled() && parent::canAccess();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Commission rule');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Commission rules');
    }

    public static function form(Schema $schema): Schema
    {
        $perProduct = fn (Get $get) => $get('basis') === CommissionBasis::PerProduct->value;

        return $schema->columns(2)->components([
            Select::make('doctor_id')->label(__('Doctor'))->options(fn () => User::doctorOptions())->native(false)->placeholder(__('All doctors')),
            Select::make('basis')->label(__('Earned'))->options(CommissionBasis::options())->required()->native(false)->live()->default(CommissionBasis::PerSurgery->value),
            Select::make('surgery_type_id')->label(__('Surgery type'))->options(fn () => SurgeryType::query()->orderBy('code')->get()->mapWithKeys(fn (SurgeryType $t) => [$t->id => $t->name]))
                ->native(false)->placeholder(__('Any'))->hidden($perProduct),
            Select::make('product_id')->label(__('Product'))->searchable()->visible($perProduct)
                ->getSearchResultsUsing(fn (string $s) => Product::query()->active()->search($s)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                ->getOptionLabelUsing(fn ($v) => Product::query()->find($v)?->label()),
            Select::make('category_id')->label(__('or product category'))->options(fn () => Category::query()->orderBy('code')->get()->mapWithKeys(fn (Category $c) => [$c->id => $c->name]))
                ->native(false)->visible($perProduct),
            Select::make('calc_type')->label(__('Amount is'))->options(CommissionCalc::options())->required()->native(false)->live()->default(CommissionCalc::Percent->value),
            TextInput::make('value')->label(fn (Get $get) => $get('calc_type') === CommissionCalc::Fixed->value ? __('Amount') : __('Percent of the price'))
                ->numeric()->minValue(0)->required(),
            Select::make('currency')->label(__('Currency'))->options(fn () => Currency::options())->native(false)
                ->required(fn (Get $get) => $get('calc_type') === CommissionCalc::Fixed->value)
                ->visible(fn (Get $get) => $get('calc_type') === CommissionCalc::Fixed->value),
            DatePicker::make('valid_from')->label(__('From'))->native(false)->displayFormat('Y-m-d'),
            DatePicker::make('valid_to')->label(__('Until'))->native(false)->displayFormat('Y-m-d'),
            Toggle::make('is_active')->label(__('Active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['doctor', 'product', 'category']))
            ->columns([
                TextColumn::make('doctor.name')->label(__('Doctor'))->placeholder(__('All doctors')),
                TextColumn::make('basis')->label(__('Earned'))->badge()->color('gray'),
                TextColumn::make('applies_to')->label(__('For'))->state(fn (CommissionRule $r) => match (true) {
                    $r->product_id !== null => $r->product->label(),
                    $r->category_id !== null => $r->category->name,
                    default => '—',
                }),
                TextColumn::make('value')->label(__('Commission'))->alignEnd()
                    ->state(fn (CommissionRule $r) => $r->calc_type === CommissionCalc::Fixed ? Display::money($r->value, $r->currency) : Display::number($r->value, 2).'%'),
                TextColumn::make('valid_from')->label(__('From'))->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state))->toggleable(),
                IconColumn::make('is_active')->label(__('Active'))->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No commission rules'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommissionRules::route('/')];
    }
}
