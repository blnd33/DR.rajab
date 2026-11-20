<?php

namespace App\Services\Insight;

use App\Filament\Admin\Resources\Branches\BranchResource;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Support\Database\Pg;
use App\Support\Locale\DigitConverter;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * The big search on the Home page: one box for the whole system. Codes first (serial, lot,
 * barcode, SKU, instrument set, document numbers — the Quick answers lookup), then every admin
 * list the user may open (Filament's search), plus names kept in three languages (products,
 * hospitals, stations), which that search does not read.
 */
class SystemSearch
{
    public const PER_GROUP = 5;

    public function __construct(private readonly CodeLookup $codes) {}

    /**
     * @return list<array{group: string, items: list<array{title: string, details: ?string, url: ?string}>}>
     */
    public function search(User $user, string $text): array
    {
        $text = mb_substr(trim((string) DigitConverter::toWestern($text)), 0, 100);

        if (mb_strlen($text) < 2) {
            return [];
        }

        $groups = [];
        $seen = [];

        if (! preg_match('/\s/u', $text)) {
            foreach ($this->codes->find($user, $text) as $card) {
                $this->add($groups, $seen, __('Codes'), $card->title, $card->headline, $card->url);
            }
        }

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            /** @var class-string<resource> $resource */
            try {
                if (! $resource::canGloballySearch()) {
                    continue;
                }

                foreach ($resource::getGlobalSearchResults($text)->take(self::PER_GROUP) as $result) {
                    $details = collect($result->details)->map(fn ($value, $label) => is_string($label) ? "{$label}: {$value}" : (string) $value)->implode(' · ');
                    $this->add($groups, $seen, $resource::getPluralModelLabel(), (string) $result->title, $details ?: null, $result->url);
                }
            } catch (\Throwable $e) {
                // One list failing must not break the search.
                Log::warning('Home page search: '.$resource.' failed', ['error' => $e->getMessage()]);
            }
        }

        $this->names($user, $text, $groups, $seen);

        return array_values(array_map(fn (string $group, array $items) => ['group' => $group, 'items' => array_slice($items, 0, self::PER_GROUP)], array_keys($groups), $groups));
    }

    /**
     * Products, hospitals and stations by their name in any language.
     *
     * @param  array<string, list<array{title: string, details: ?string, url: ?string}>>  $groups
     * @param  array<string, true>  $seen
     */
    private function names(User $user, string $text, array &$groups, array &$seen): void
    {
        $lists = [
            [ProductResource::class, Product::class, 'sku', 'edit'],
            [CustomerResource::class, Customer::class, 'code', 'view'],
            [BranchResource::class, Branch::class, 'code', 'edit'],
        ];

        foreach ($lists as [$resource, $model, $codeColumn, $page]) {
            if (! $resource::canViewAny()) {
                continue;
            }

            /** @var class-string<Model> $model */
            $records = $model::query()->whereRaw(Pg::jsonContainsSql('name'), ["%{$text}%"])->limit(self::PER_GROUP)->get();

            foreach ($records as $record) {
                $title = (string) $record->getAttribute('name');
                $code = (string) $record->getAttribute($codeColumn);
                $url = $resource::hasPage($page) && ($page === 'view' ? $resource::canView($record) : $resource::canEdit($record))
                    ? $resource::getUrl($page, ['record' => $record])
                    : null;
                $this->add($groups, $seen, $resource::getPluralModelLabel(), (string) $title, $code, $url);
            }
        }
    }

    /**
     * @param  array<string, list<array{title: string, details: ?string, url: ?string}>>  $groups
     * @param  array<string, true>  $seen
     */
    private function add(array &$groups, array &$seen, string $group, string $title, ?string $details, ?string $url): void
    {
        $key = $url ?? $group.'|'.$title;

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $groups[$group][] = ['title' => $title, 'details' => $details, 'url' => $url];
    }
}
