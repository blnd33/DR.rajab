<?php

namespace App\Filament\Admin\Resources\Agents;

use App\Enums\AgentType;
use App\Enums\Role;
use App\Models\CompanyAgent;
use App\Models\User;
use App\Services\Agents\CompanyAgentService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The company-agent form: name and phone, then only the information the type keeps
 * (AgentType::fields()). Money is an amount with its currency (IQD, USD or EUR).
 */
final class AgentForm
{
    /**
     * @return list<mixed>
     */
    public static function components(AgentType $type, bool $editing = false): array
    {
        return array_values(array_filter([
            $type !== AgentType::Technician ? TextInput::make('name')->label(__('Full name'))->required()->maxLength(150) : null,
            $type !== AgentType::Technician ? TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32)->extraInputAttributes(['dir' => 'ltr']) : null,
            ...self::details($type),
            $type !== AgentType::Technician ? Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull() : null,
            $editing && $type !== AgentType::Technician ? Toggle::make('is_active')->label(__('Active'))->helperText(__('Inactive agents leave the lists; their information is kept.')) : null,
        ]));
    }

    /**
     * The fields of the type only (also used inside the technician form).
     *
     * @return list<mixed>
     */
    public static function details(AgentType $type): array
    {
        return array_values(array_filter([
            $type->has('distributor') ? Select::make('distributor_id')->label(__('Distributor'))->native(false)->searchable()->required()
                ->options(fn () => CompanyAgent::query()->ofType(AgentType::Distributor)->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->helperText(__('The distributor this sub distributor works under.')) : null,
            $type->has('city') ? TextInput::make('city')->label(__('City'))->maxLength(120) : null,
            $type->has('position') ? TextInput::make('position')->label(__('Position'))->maxLength(120) : null,
            $type->has('user') ? Select::make('user_id')->label(__('System account (optional)'))->native(false)->searchable()
                ->options(fn () => User::query()->whereDoesntHave('roles', fn ($r) => $r->where('name', Role::Technician->value))
                    ->orderBy('name')->pluck('name', 'id')->all())
                ->helperText(__('If this employee also uses the system, link their account.')) : null,
            $type->has('persons') ? Textarea::make('persons')->label(__('Persons'))->rows(2)->columnSpanFull()
                ->helperText(__('The people who work with this seller.')) : null,
            $type->has('stock') ? TextInput::make('stock')->label(__('Stock'))->maxLength(255)
                ->helperText(__('What goods they hold (for your information).')) : null,
            $type->has('shares') ? TextInput::make('shares_percent')->label(__('Stocks (share %)'))->numeric()->minValue(0)->maxValue(100)->suffix('%') : null,
            $type->has('value') ? self::money('value', __('Value')) : null,
            $type->has('salary') ? self::money('salary', __('Salary (per month)')) : null,
            $type->has('mission_fee') ? self::money('mission_fee', __('Fee per mission')) : null,
            $type->has('profit') ? Grid::make(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                TextInput::make('profit_month_amount')->label(__('Profit per month'))->numeric()->minValue(0),
                TextInput::make('profit_year_amount')->label(__('Profit per year'))->numeric()->minValue(0)
                    ->helperText(__('Empty = profit per month × 12.')),
                self::currency('profit_currency'),
            ]) : null,
            $type->has('cv') ? FileUpload::make('cv')->label(__('CV'))->disk('local')->directory('tmp/agents')->visibility('private')
                ->storeFileNamesIn('cv_name')
                ->acceptedFileTypes(CompanyAgentService::CV_TYPES)->maxSize(10240)->columnSpanFull()
                ->helperText(__('PDF, Word or a photo, up to 10 MB. A new CV is kept next to the old ones.')) : null,
        ]));
    }

    /**
     * Form values of a saved agent (for the edit form).
     *
     * @return array<string, mixed>
     */
    public static function fill(?CompanyAgent $agent): array
    {
        if ($agent === null) {
            return [];
        }

        return collect($agent->only([
            'name', 'phone', 'city', 'user_id', 'distributor_id', 'persons', 'stock', 'value_amount', 'value_currency', 'shares_percent',
            'profit_month_amount', 'profit_year_amount', 'profit_currency', 'position', 'salary_amount', 'salary_currency',
            'mission_fee_amount', 'mission_fee_currency', 'notes', 'is_active',
        ]))->map(fn ($value) => is_string($value) && is_numeric($value) && str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value)->all();
    }

    /**
     * The uploaded CV of the form, as a file the service can store (null when none was added).
     *
     * @param  array<string, mixed>  $data
     */
    public static function cv(array $data): ?UploadedFile
    {
        $path = $data['cv'] ?? null;
        $path = is_array($path) ? (array_values($path)[0] ?? null) : $path;

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $name = $data['cv_name'] ?? null;
        $name = is_array($name) ? (array_values($name)[0] ?? null) : $name;

        return new UploadedFile(Storage::disk('local')->path($path), (string) ($name ?: basename($path)), Storage::disk('local')->mimeType($path) ?: null, null, true);
    }

    /**
     * Remove the temporary upload once the service has kept its own copy.
     *
     * @param  array<string, mixed>  $data
     */
    public static function forgetUpload(array $data): void
    {
        foreach ((array) ($data['cv'] ?? []) as $path) {
            if (is_string($path) && str_starts_with($path, 'tmp/agents/')) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private static function money(string $prefix, string $label): Grid
    {
        return Grid::make(['default' => 2])->columnSpanFull()->schema([
            TextInput::make("{$prefix}_amount")->label($label)->numeric()->minValue(0),
            self::currency("{$prefix}_currency"),
        ]);
    }

    private static function currency(string $name): Select
    {
        $currencies = config('orthotrack.currencies');

        return Select::make($name)->label(__('Currency'))->native(false)
            ->options(array_combine($currencies, $currencies))
            ->default('IQD');
    }
}
