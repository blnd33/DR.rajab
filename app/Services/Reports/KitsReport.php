<?php

namespace App\Services\Reports;

use App\Enums\CheckoutItemOutcome;
use App\Enums\KitCheckoutStatus;
use App\Enums\SurgeryStatus;
use App\Models\Branch;
use App\Models\Kit;
use App\Models\KitCheckout;
use App\Models\KitCheckoutItem;
use App\Models\Surgery;

/**
 * How the kits are used (Module 18 / 24): checkouts in the period, surgeries done from each
 * kit, implants used, items still unaccounted for and kits out or overdue right now.
 */
class KitsReport extends Report
{
    public static function key(): string
    {
        return 'kits';
    }

    public function title(): string
    {
        return __('Instrument sets report');
    }

    public function description(): ?string
    {
        return __('Checkouts and surgeries per instrument set in the period; out and overdue as of now.');
    }

    public function filters(): array
    {
        return ['period', 'branch'];
    }

    public function columns(ReportContext $context): array
    {
        return [
            ReportColumn::code('code', __('Instrument set')),
            ReportColumn::text('type', __('Instrument set type')),
            ReportColumn::text('branch', __('Station')),
            ReportColumn::number('checkouts', __('Checkouts')),
            ReportColumn::number('surgeries', __('Surgeries')),
            ReportColumn::number('used', __('Implants used')),
            ReportColumn::number('unaccounted', __('Unaccounted')),
            ReportColumn::text('now', __('Now')),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $kits = Kit::query()->with(['type', 'currentCheckout'])
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->orderBy('code')->get();

        $checkouts = KitCheckout::query()->whereIn('kit_id', $kits->pluck('id'))
            ->whereBetween('checked_out_at', $context->bounds())
            ->get()->groupBy('kit_id');
        $kitOfCheckout = $checkouts->flatten()->pluck('kit_id', 'id');
        $items = KitCheckoutItem::query()->whereIn('kit_checkout_id', $kitOfCheckout->keys())
            ->get()->groupBy(fn (KitCheckoutItem $i) => $kitOfCheckout[$i->kit_checkout_id] ?? 0);
        $surgeries = Surgery::query()->whereIn('kit_id', $kits->pluck('id'))->where('status', SurgeryStatus::Completed)
            ->whereBetween('surgery_date', [$context->from->toDateString(), $context->until->toDateString()])
            ->selectRaw('kit_id, COUNT(*) AS n')->groupBy('kit_id')->pluck('n', 'kit_id');
        $branches = self::names(Branch::class, $kits->pluck('branch_id'));

        return $kits->map(function (Kit $kit) use ($checkouts, $items, $surgeries, $branches) {
            $open = $kit->currentCheckout;
            $kitItems = $items->get($kit->id, collect());

            return [
                'key' => 'k'.$kit->id,
                'code' => $kit->code,
                'type' => $kit->type->name ?? '—',
                'branch' => $branches[$kit->branch_id] ?? '—',
                'checkouts' => $checkouts->get($kit->id, collect())->count(),
                'surgeries' => (int) ($surgeries[$kit->id] ?? 0),
                'used' => (int) $kitItems->sum('qty_used'),
                'unaccounted' => (int) $kitItems->filter(fn (KitCheckoutItem $i) => $i->outcome === CheckoutItemOutcome::Unaccounted && $i->resolved_at === null)->sum('qty_unaccounted'),
                'now' => match (true) {
                    $open !== null && $open->status === KitCheckoutStatus::Out && $open->due_back_at !== null && $open->due_back_at->isPast() => __('Overdue'),
                    $open !== null && $open->status === KitCheckoutStatus::Out => __('Out at a hospital'),
                    default => $kit->status->getLabel(),
                },
            ];
        })->values()->all();
    }
}
