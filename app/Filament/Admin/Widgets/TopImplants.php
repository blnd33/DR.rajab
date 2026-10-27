<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\SurgeryStatus;
use App\Models\Product;
use App\Models\SurgeryItem;
use App\Models\User;
use App\Services\Clinical\SurgeryService;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/**
 * The ten implants used most in the last 90 days (P5-5), from the surgeries the user may see.
 */
class TopImplants extends TableWidget
{
    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 2];

    public static function canView(): bool
    {
        $user = Auth::user();
        $scope = app(BranchScopeResolver::class);

        return $user instanceof User && $user->can('dashboard.view') && $scope->hasAny($user, 'reports.view') && $scope->hasAny($user, 'surgeries.view_any');
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Most used implants (90 days)'))
            ->records(function () {
                /** @var User $user */
                $user = Auth::user();
                $surgeries = app(SurgeryService::class)->visibleTo($user)->where('status', SurgeryStatus::Completed)
                    ->whereDate('surgery_date', '>=', now(config('orthotrack.timezone'))->subDays(90)->toDateString())
                    ->select('id');

                $rows = SurgeryItem::query()->whereIn('surgery_id', $surgeries)
                    ->whereNotNull('used_at')->whereNull('reversed_at')
                    ->groupBy('product_id')
                    ->selectRaw('product_id, SUM(quantity) AS used, COUNT(DISTINCT surgery_id) AS surgeries')
                    ->orderByDesc('used')->limit(10)
                    ->toBase()->get();
                $products = Product::query()->whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');

                return $rows->map(fn ($r) => [
                    'key' => 'p'.$r->product_id,
                    'sku' => $products[$r->product_id]->sku ?? '—',
                    'product' => $products[$r->product_id]->name ?? '—',
                    'used' => (int) $r->used,
                    'surgeries' => (int) $r->surgeries,
                ])->values();
            })
            ->columns([
                TextColumn::make('sku')->label(__('SKU'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('product')->label(__('Product')),
                TextColumn::make('used')->label(__('Used'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('surgeries')->label(__('Surgeries'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('No surgeries in the last 90 days'));
    }
}
