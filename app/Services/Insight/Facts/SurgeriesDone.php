<?php

namespace App\Services\Insight\Facts;

use App\Enums\SurgeryStatus;
use App\Models\Customer;
use App\Services\Clinical\SurgeryService;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;

/**
 * Surgeries completed in the period, by hospital. Same visibility as the surgeries list
 * (without patients.view_all only the person's own surgeries).
 */
class SurgeriesDone extends Fact
{
    public function __construct(private readonly SurgeryService $surgeries) {}

    public static function key(): string
    {
        return 'surgeries-done';
    }

    public function title(): string
    {
        return __('Surgeries');
    }

    public function section(): FactSection
    {
        return FactSection::Day;
    }

    protected function permission(): string
    {
        return 'surgeries.view_any';
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    public function build(FactContext $context): FactResult
    {
        $query = $context->scope($this->surgeries->visibleTo($context->user), 'surgeries.view_any')
            ->where('status', SurgeryStatus::Completed)
            ->whereBetween('surgery_date', $context->dates());

        $total = (clone $query)->count();
        $byHospital = (clone $query)->toBase()->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) AS n')->orderByDesc('n')->limit(self::MAX_LINES)
            ->pluck('n', 'customer_id');
        $names = Customer::query()->whereIn('id', $byHospital->keys()->filter())->get()->mapWithKeys(fn (Customer $c) => [$c->id => $c->name]);

        $lines = $byHospital->map(fn ($n, $id) => self::line($names[$id] ?? __('No hospital'), self::count((int) $n)))->values()->all();

        return $this->result($context, self::count($total), $lines, ['surgeries' => $total], 'filament.admin.resources.surgeries.index');
    }
}
