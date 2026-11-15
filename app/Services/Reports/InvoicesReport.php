<?php

namespace App\Services\Reports;

use App\Enums\SalesInvoiceStatus;
use App\Models\SalesInvoice;
use App\Services\Sales\InvoiceDescriber;
use Brick\Math\BigDecimal;

/**
 * Invoices of a period (sales and surgeries), paid and not paid: per invoice (who owes it, what
 * for, the doctor), or added up per person, per doctor or paid / not paid.
 */
class InvoicesReport extends Report
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    public static function key(): string
    {
        return 'invoices';
    }

    public function title(): string
    {
        return __('Invoices report');
    }

    public function description(): ?string
    {
        return __('Sales and surgery invoices of the period: what was paid and what is still owed, by whom and for what.');
    }

    public function filters(): array
    {
        return ['period', 'branch', 'currency', 'group'];
    }

    public function groups(): array
    {
        return [
            'invoice' => __('Each invoice'),
            'buyer' => __('Who owes'),
            'doctor' => __('Doctor'),
            'status' => __('Paid / not paid'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $head = match ($context->group) {
            'buyer' => [ReportColumn::text('label', __('Who owes')), ReportColumn::number('invoices', __('Invoices'))],
            'doctor' => [ReportColumn::text('label', __('Doctor')), ReportColumn::number('invoices', __('Invoices'))],
            'status' => [ReportColumn::text('label', __('Status')), ReportColumn::number('invoices', __('Invoices'))],
            default => [
                ReportColumn::code('number', __('Number')),
                ReportColumn::date('date', __('Date')),
                ReportColumn::text('buyer', __('Who owes')),
                ReportColumn::text('for', __('For')),
                ReportColumn::text('doctor', __('Doctor')),
                ReportColumn::text('status', __('Status')),
            ],
        };

        return [
            ...$head,
            ReportColumn::money('total', __('Total')),
            ReportColumn::money('paid', __('Paid')),
            ReportColumn::money('owed', __('Still owed')),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $invoices = SalesInvoice::query()->with(InvoiceDescriber::WITH)
            ->whereNotIn('status', [SalesInvoiceStatus::Draft->value, SalesInvoiceStatus::Voided->value])
            ->whereBetween('issued_at', $context->bounds())
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds === [] ? [0] : $context->branchIds))
            ->orderBy('issued_at')
            ->get();

        $rows = [];

        foreach ($invoices as $invoice) {
            /** @var SalesInvoice $invoice */
            $total = $this->fx->convert((string) $invoice->total, $invoice->currency, $context->currency, $invoice->fx_snapshot_id, $context) ?? BigDecimal::zero();
            $paid = $this->fx->convert((string) $invoice->paid_amount, $invoice->currency, $context->currency, $invoice->fx_snapshot_id, $context) ?? BigDecimal::zero();
            $status = $invoice->status === SalesInvoiceStatus::Issued ? __('Not paid') : $invoice->status->getLabel();
            $buyer = InvoiceDescriber::buyer($invoice);
            $doctor = InvoiceDescriber::doctor($invoice);

            [$key, $label] = match ($context->group) {
                'buyer' => ['b'.mb_strtolower($buyer), $buyer],
                'doctor' => ['d'.mb_strtolower($doctor), $doctor],
                'status' => ['s'.$invoice->status->value, $status],
                default => ['i'.$invoice->id, $invoice->number],
            };

            $rows[$key] ??= [
                'key' => $key,
                'label' => $label,
                'number' => $invoice->number,
                'date' => $invoice->issued_at?->setTimezone(config('orthotrack.timezone'))->toDateString(),
                'buyer' => $buyer,
                'for' => InvoiceDescriber::forWhat($invoice),
                'doctor' => $doctor,
                'status' => $status,
                'invoices' => 0,
                'total' => '0',
                'paid' => '0',
                'owed' => '0',
            ];
            $rows[$key]['invoices']++;
            $rows[$key]['total'] = self::money(BigDecimal::of($rows[$key]['total'])->plus($total));
            $rows[$key]['paid'] = self::money(BigDecimal::of($rows[$key]['paid'])->plus($paid));
            $rows[$key]['owed'] = self::money(BigDecimal::of($rows[$key]['total'])->minus($rows[$key]['paid']));
        }

        $rows = array_values($rows);

        if ($context->group !== 'invoice' && $context->group !== null) {
            usort($rows, fn (array $a, array $b) => (float) $b['owed'] <=> (float) $a['owed'] ?: (float) $b['total'] <=> (float) $a['total']);
        }

        return $rows;
    }
}
