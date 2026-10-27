<?php

namespace App\Http\Controllers;

use App\Models\CashBoxClosing;
use App\Models\KitCheckout;
use App\Models\PurchaseReceipt;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\Surgery;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Money\PartyLedgerService;
use App\Services\Pdf\PdfRenderer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Print pages (and PDFs) of single documents (P5-3), each behind the same permission as the
 * document's own screen.
 */
class DocumentPrintController extends Controller
{
    /** @var array<string, array{0: class-string<Model>, 1: string}> type => [model, view] */
    public const DOCUMENTS = [
        'sales-invoice' => [SalesInvoice::class, 'print.documents.sales-invoice'],
        'goods-received' => [PurchaseReceipt::class, 'print.documents.goods-received'],
        'transfer' => [Transfer::class, 'print.documents.transfer'],
        'surgery' => [Surgery::class, 'print.documents.surgery'],
        'kit-checkout' => [KitCheckout::class, 'print.documents.kit-checkout'],
        'supplier-statement' => [Supplier::class, 'print.documents.supplier-statement'],
        'cash-closing' => [CashBoxClosing::class, 'print.documents.cash-closing'],
    ];

    public static function url(string $type, Model $record): string
    {
        return route('documents.print', ['type' => $type, 'id' => $record->getKey()]);
    }

    public function __invoke(Request $request, string $type, int $id, PdfRenderer $pdf, PartyLedgerService $party): Response
    {
        abort_unless(isset(self::DOCUMENTS[$type]), 404);
        [$class, $view] = self::DOCUMENTS[$type];

        /** @var User $user */
        $user = $request->user();
        $record = $class::query()->findOrFail($id);
        $this->authorizeView($user, $type, $record);

        $data = ['record' => $record, 'pdfUrl' => $pdf->available() ? $request->fullUrlWithQuery(['pdf' => 1]) : null];

        if ($record instanceof Supplier) {
            $data['entries'] = $party->statement($record)->with('reference')->get();
            $data['balances'] = $party->balances($record);
        }

        $html = view($view, $data)->render();

        if ($request->boolean('pdf')) {
            $download = $pdf->download($html, $type.'-'.$this->number($record).'.pdf');

            if ($download !== null) {
                return $download;
            }
        }

        return response($html);
    }

    private function authorizeView(User $user, string $type, Model $record): void
    {
        $allowed = match (true) {
            $record instanceof PurchaseReceipt => $user->can('view', $record->purchase),
            $record instanceof Supplier => $user->can('view', $record) && $user->can('purchases.view_any'),
            default => $user->can('view', $record),
        };

        abort_unless($allowed, 403);
    }

    private function number(Model $record): string
    {
        return (string) ($record->getAttribute('number') ?? $record->getAttribute('code') ?? $record->getKey());
    }
}
