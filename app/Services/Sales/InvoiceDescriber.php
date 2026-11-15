<?php

namespace App\Services\Sales;

use App\Enums\InvoiceKind;
use App\Enums\SalesInvoiceStatus;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Plain words for an invoice, shared by the dashboard and the invoices report: who owes it,
 * what it is for, and the doctor (the lead surgeon of a surgery, the seller of a sale).
 */
final class InvoiceDescriber
{
    /** Relations the descriptions read. */
    public const WITH = ['customer', 'patient', 'creator', 'items.product', 'surgery.type', 'surgery.patient', 'surgery.doctors'];

    public static function buyer(SalesInvoice $invoice): string
    {
        $name = $invoice->customer->name ?? ($invoice->walk_in_name ?: null);
        $patient = $invoice->kind === InvoiceKind::Surgery ? $invoice->surgery?->patientName() : null;

        if ($name === null) {
            return $patient ?? __('Walk-in');
        }

        return $patient !== null && $patient !== $name ? $name.' · '.$patient : $name;
    }

    public static function forWhat(SalesInvoice $invoice): string
    {
        if ($invoice->kind === InvoiceKind::Surgery) {
            return trim(__('Surgery').': '.($invoice->surgery->type->name ?? '—'));
        }

        $names = $invoice->items->map(fn (SalesInvoiceItem $i) => ($i->product->name ?? '—').($i->quantity > 1 ? ' × '.$i->quantity : ''));
        $shown = $names->take(3)->implode(', ');

        return $names->count() > 3 ? __(':items and :count more', ['items' => $shown, 'count' => $names->count() - 3]) : ($shown ?: '—');
    }

    public static function doctor(SalesInvoice $invoice): string
    {
        if ($invoice->kind === InvoiceKind::Surgery) {
            return $invoice->surgery?->leadDoctor()->name ?? '—';
        }

        return $invoice->creator->name ?? '—';
    }

    public static function owed(SalesInvoice $invoice): string
    {
        return (string) BigDecimal::of($invoice->total)->minus($invoice->paid_amount)->toScale(4, RoundingMode::HalfUp);
    }

    public static function isOpen(SalesInvoice $invoice): bool
    {
        return in_array($invoice->status, [SalesInvoiceStatus::Issued, SalesInvoiceStatus::PartiallyPaid], true);
    }

    /**
     * Invoices still waiting for money (unpaid or partly paid).
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function open(Builder $query): Builder
    {
        return $query->whereIn('status', [SalesInvoiceStatus::Issued, SalesInvoiceStatus::PartiallyPaid]);
    }
}
