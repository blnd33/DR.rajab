<?php

namespace App\Services\Reports;

use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryStatus;
use App\Models\Customer;
use App\Models\Surgery;
use App\Models\SurgeryItem;
use App\Models\SurgeryType;
use App\Models\User;
use App\Services\Clinical\SurgeryService;
use Brick\Math\BigDecimal;

/**
 * Completed surgeries (Module 24): per lead surgeon, hospital, surgery type or month — how
 * many, the implants used and their true cost, and the fees. Only surgeries the user may see.
 */
class SurgeriesReport extends Report
{
    public function __construct(
        private readonly SnapshotConverters $fx,
        private readonly SurgeryService $surgeries,
    ) {}

    public static function key(): string
    {
        return 'surgeries';
    }

    public function title(): string
    {
        return __('Surgeries report');
    }

    public function description(): ?string
    {
        return __('Completed surgeries with the implants used and their true cost.');
    }

    public function filters(): array
    {
        return ['period', 'branch', 'currency', 'group'];
    }

    public function groups(): array
    {
        return [
            'doctor' => __('Lead surgeon'),
            'hospital' => __('Hospital'),
            'type' => __('Surgery type'),
            'month' => __('Month'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $head = match ($context->group) {
            'hospital' => ReportColumn::text('label', __('Hospital')),
            'type' => ReportColumn::text('label', __('Surgery type')),
            'month' => ReportColumn::code('label', __('Month')),
            default => ReportColumn::text('label', __('Lead surgeon')),
        };

        return [
            $head,
            ReportColumn::number('surgeries', __('Surgeries')),
            ReportColumn::number('implants', __('Implants used')),
            ReportColumn::money('implant_cost', __('Implant cost'), profit: true),
            ReportColumn::money('fees', __('Fees')),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $surgeries = $this->surgeries->visibleTo($context->user)
            ->where('status', SurgeryStatus::Completed)
            ->whereBetween('surgery_date', [$context->from->toDateString(), $context->until->toDateString()])
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->with(['doctors'])
            ->get();

        $items = SurgeryItem::query()
            ->leftJoin('stock_units', 'stock_units.id', '=', 'surgery_items.stock_unit_id')
            ->leftJoin('stock_batches', 'stock_batches.id', '=', 'surgery_items.stock_batch_id')
            ->whereIn('surgery_items.surgery_id', $surgeries->pluck('id'))
            ->whereNotNull('surgery_items.used_at')->whereNull('surgery_items.reversed_at')
            ->selectRaw('surgery_items.surgery_id, surgery_items.quantity, surgery_items.unit_cost, surgery_items.cost_currency, COALESCE(stock_units.cost_fx_snapshot_id, stock_batches.cost_fx_snapshot_id) AS snapshot_id')
            ->toBase()->get()->groupBy('surgery_id');

        $customers = self::names(Customer::class, $surgeries->pluck('customer_id'));
        $types = self::names(SurgeryType::class, $surgeries->pluck('surgery_type_id'));
        $rows = [];

        foreach ($surgeries as $surgery) {
            /** @var Surgery $surgery */
            /** @var User|null $lead */
            $lead = $surgery->doctors->first(fn (User $d) => $d->getRelationValue('pivot')?->getAttribute('role') === SurgeryDoctorRole::Lead->value) ?? $surgery->doctors->first();

            [$key, $label] = match ($context->group) {
                'hospital' => ['h'.($surgery->customer_id ?? 0), $customers[(int) $surgery->customer_id] ?? '—'],
                'type' => ['t'.($surgery->surgery_type_id ?? 0), $types[(int) $surgery->surgery_type_id] ?? '—'],
                'month' => [$surgery->surgery_date->format('Y-m'), $surgery->surgery_date->format('Y-m')],
                default => ['d'.($lead->id ?? 0), $lead->name ?? '—'],
            };

            $rows[$key] ??= ['key' => $key, 'label' => $label, 'surgeries' => 0, 'implants' => 0, 'implant_cost' => '0', 'fees' => '0'];
            $rows[$key]['surgeries']++;

            foreach ($items->get($surgery->id, collect()) as $item) {
                $cost = BigDecimal::of((string) ($item->unit_cost ?? '0'))->multipliedBy((int) $item->quantity);
                $converted = $this->fx->convert((string) $cost, $item->cost_currency, $context->currency, $item->snapshot_id !== null ? (int) $item->snapshot_id : null, $context) ?? BigDecimal::zero();
                $rows[$key]['implants'] += (int) $item->quantity;
                $rows[$key]['implant_cost'] = self::money(BigDecimal::of($rows[$key]['implant_cost'])->plus($converted));
            }

            if ($surgery->fee_amount !== null) {
                $fee = $this->fx->convert($surgery->fee_amount, $surgery->fee_currency, $context->currency, $surgery->getAttribute('fee_fx_snapshot_id'), $context) ?? BigDecimal::zero();
                $rows[$key]['fees'] = self::money(BigDecimal::of($rows[$key]['fees'])->plus($fee));
            }
        }

        return array_values($rows);
    }
}
