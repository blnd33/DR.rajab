<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\Specialty;
use App\Filament\Admin\Pages\OverviewPage;
use App\Models\User;
use App\Services\Insight\OverviewService;
use App\Services\Insight\SystemSearch;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * The centre of the Home page: the specialties (arthroplasty, trauma, spine, sport medicine)
 * and the search over the whole system on the first row; stations, stock, value, sales and
 * profit on the second. Each button opens the overview page.
 */
class OverviewLauncher extends Widget
{
    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    /** The main buttons show at once, not after the page has loaded. */
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.overview-launcher';

    public string $search = '';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $metrics = app(OverviewService::class)->metrics($user);

        return [
            'specialties' => $metrics === [] ? [] : array_map(fn (Specialty $s) => [
                'label' => $s->getLabel(),
                'icon' => $s->icon(),
                'url' => OverviewPage::getUrl(['focus' => $s->value]),
            ], Specialty::cases()),
            'metrics' => array_map(fn ($m) => [
                'label' => $m->getLabel(),
                'icon' => $m->icon(),
                'url' => OverviewPage::getUrl(['metric' => $m->value]),
            ], $metrics),
            'results' => mb_strlen(trim($this->search)) >= 2 ? app(SystemSearch::class)->search($user, $this->search) : null,
        ];
    }
}
