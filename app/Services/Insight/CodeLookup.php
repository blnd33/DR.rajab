<?php

namespace App\Services\Insight;

use App\Enums\Panel as AppPanel;
use App\Enums\ScanContext;
use App\Filament\Admin\Resources;
use App\Models\Branch;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\Patient;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockOnHand;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Assets\AssetLookup;
use App\Services\Assets\KitService;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResult;
use App\Services\Barcode\ScanResultKind;
use App\Services\Clinical\PatientService;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\SerialHistory;
use App\Support\Locale\DigitConverter;
use App\Support\Locale\Display;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * "Type or scan anything" in Quick answers: a typed or scanned code → answer cards.
 * Document numbers (TRF-…, SRG-…, INV-…), patient file numbers, kits and instruments, then
 * everything the scanner reads (serial, SKU, GTIN / GS1, extra barcode, lot). Each card shows
 * only what the person may see; documents go through the admin screen's own query, so the
 * branch rules are the same as on that screen. Needs the asking person to be logged in.
 */
final class CodeLookup
{
    /** Document number prefix → admin resource (its query and permission decide who sees it). */
    public const DOCUMENTS = [
        'TRF' => Resources\Transfers\TransferResource::class,
        'SRG' => Resources\Surgeries\SurgeryResource::class,
        'INV' => Resources\SalesInvoices\SalesInvoiceResource::class,
        'KCO' => Resources\KitCheckouts\KitCheckoutResource::class,
        'PUR' => Resources\Purchases\PurchaseResource::class,
        'RCP' => Resources\CustomerPayments\CustomerPaymentResource::class,
        'SPY' => Resources\SupplierPayments\SupplierPaymentResource::class,
        'EXP' => Resources\Expenses\ExpenseResource::class,
        'BXT' => Resources\BoxTransfers\BoxTransferResource::class,
        'CEX' => Resources\CurrencyExchanges\CurrencyExchangeResource::class,
        'RET' => Resources\CustomerReturns\CustomerReturnResource::class,
        'SRT' => Resources\SupplierReturns\SupplierReturnResource::class,
        'WOF' => Resources\WriteOffs\WriteOffResource::class,
        'CNT' => Resources\StockCounts\StockCountResource::class,
        'REQ' => Resources\StockRequests\StockRequestResource::class,
        'CRN' => Resources\CreditNotes\CreditNoteResource::class,
    ];

    /** Card keys of single things found by code (they link to the thing, not to a list). */
    public const KINDS = ['document', 'patient', 'kit', 'instrument', 'unit', 'product', 'lot'];

    /** Phone screens for documents that have one. */
    private const OPS_ROUTES = [
        'TRF' => 'ops.transfers.show',
        'SRG' => 'ops.surgeries.show',
        'REQ' => 'ops.requests.show',
        'CNT' => 'ops.counts.show',
    ];

    public function __construct(
        private readonly ScanResolver $scanner,
        private readonly AssetLookup $assets,
        private readonly PatientService $patients,
        private readonly KitService $kits,
        private readonly SerialHistory $history,
    ) {}

    /**
     * @param  bool  $phone  links to the phone screens where they exist
     * @return list<FactResult> empty when nothing (visible) has this code
     */
    public function find(User $user, string $raw, bool $phone = false): array
    {
        $code = trim((string) DigitConverter::toWestern($raw));

        if ($code === '' || mb_strlen($code) > 500) {
            return [];
        }

        if (preg_match('/^([A-Za-z]{3})-/', $code, $m)) {
            $prefix = mb_strtoupper($m[1]);

            if ($prefix === 'PAT') {
                return array_values(array_filter([$this->patient($user, $code)]));
            }

            if (isset(self::DOCUMENTS[$prefix]) && ($card = $this->document($user, $prefix, mb_strtoupper($code), $phone)) !== null) {
                return [$card];
            }
        }

        $asset = $this->assets->find($code);

        if ($asset instanceof Kit && $user->canInBranch('kits.view_any', $asset->branch_id)) {
            return [$this->kit($user, $asset, $phone)];
        }

        if ($asset instanceof Instrument && $user->canInBranch('kits.view_any', $asset->branch_id)) {
            return [$this->instrument($asset)];
        }

        return $this->stock($user, $this->scanner->resolve($code, ScanContext::Lookup, $user, record: false), $phone);
    }

    private function document(User $user, string $prefix, string $number, bool $phone): ?FactResult
    {
        /** @var class-string<resource> $resource */
        $resource = self::DOCUMENTS[$prefix];

        if (! $resource::canViewAny()) {
            return null;
        }

        $record = $resource::getEloquentQuery()->where('number', $number)->first();

        if ($record === null) {
            return null;
        }

        $lines = [];
        $status = $record->getAttribute('status');

        if (is_object($status) && method_exists($status, 'getLabel')) {
            $lines[] = $this->line(__('Status'), (string) $status->getLabel());
        }

        foreach (['branch', 'fromBranch', 'toBranch', 'customer', 'supplier'] as $relation) {
            if (method_exists($record, $relation) && ($related = $record->getRelationValue($relation)) instanceof Model) {
                $lines[] = $this->line(match ($relation) {
                    'branch' => __('Station'),
                    'fromBranch' => __('From'),
                    'toBranch' => __('To'),
                    'customer' => __('Hospital'),
                    'supplier' => __('Supplier'),
                }, (string) ($related->getAttribute('name') ?? $related->getAttribute('code')));
            }
        }

        if ($record->getAttribute('created_at') !== null) {
            $lines[] = $this->line(__('Date'), Display::date($record->getAttribute('created_at')));
        }

        $url = null;

        if ($phone && isset(self::OPS_ROUTES[$prefix]) && Route::has(self::OPS_ROUTES[$prefix])) {
            $url = route(self::OPS_ROUTES[$prefix], $record);
        } elseif ($user->canAccessAppPanel(AppPanel::Admin)) {
            $url = $resource::hasPage('view') && $resource::canView($record)
                ? $resource::getUrl('view', ['record' => $record], panel: 'admin')
                : $resource::getUrl('index', panel: 'admin');
        }

        return new FactResult('document', $resource::getModelLabel(), $number, $lines, [], $url);
    }

    private function patient(User $user, string $code): ?FactResult
    {
        $patient = $this->patients->visibleTo($user)->where('file_number', mb_strtoupper($code))->withCount('surgeries')->first();

        if (! $patient instanceof Patient) {
            return null;
        }

        $this->patients->logView($patient, $user);

        $last = $patient->surgeries()->max('surgery_date');
        $lines = [
            $this->line(__('File number'), $patient->file_number, code: true),
            $this->line(__('Surgeries'), Display::number((int) $patient->getAttribute('surgeries_count'))),
        ];

        if ($last !== null) {
            $lines[] = $this->line(__('Last surgery'), Display::date($last));
        }

        $url = $user->canAccessAppPanel(AppPanel::Admin) && Resources\Patients\PatientResource::canView($patient)
            ? Resources\Patients\PatientResource::getUrl('view', ['record' => $patient], panel: 'admin')
            : null;

        return new FactResult('patient', __('Patient'), $patient->full_name, $lines, [], $url);
    }

    private function kit(User $user, Kit $kit, bool $phone): FactResult
    {
        $kit->loadMissing(['type', 'branch', 'customer']);
        $compare = $this->kits->compare($kit);
        $missing = array_values(array_filter($compare['lines'], fn ($l) => $l['missing'] > 0));

        $lines = [
            $this->line(__('Status'), $kit->status->getLabel().($kit->customer !== null ? ' · '.$kit->customer->name : '')),
            $this->line(__('Station'), $kit->branch->code ?? '—'),
        ];

        foreach (array_slice($missing, 0, 6) as $line) {
            $lines[] = $this->line($line['label'], __(':count missing', ['count' => Display::number($line['missing'])]));
        }

        $headline = $compare['complete']
            ? __('Complete')
            : trans_choice(':count item missing|:count items missing', $total = array_sum(array_column($missing, 'missing')), ['count' => Display::number($total)]);

        $url = $phone ? route('ops.kits.show', $kit) : ($user->canAccessAppPanel(AppPanel::Admin)
            ? Resources\Kits\KitResource::getUrl('view', ['record' => $kit], panel: 'admin') : null);

        return new FactResult('kit', __('Instrument set').' '.$kit->code.' · '.($kit->type->name ?? ''), $headline, $lines,
            ['missing' => array_sum(array_column($missing, 'missing'))], $url,
            $compare['complete'] ? FactResult::GOOD : FactResult::WARNING);
    }

    private function instrument(Instrument $instrument): FactResult
    {
        $instrument->loadMissing(['type', 'branch', 'kit', 'customer']);

        $lines = [$this->line(__('Station'), $instrument->branch->code ?? '—')];

        if ($instrument->kit !== null) {
            $lines[] = $this->line(__('Instrument set'), $instrument->kit->code, code: true);
        }

        if ($instrument->customer !== null) {
            $lines[] = $this->line(__('Hospital'), $instrument->customer->name);
        }

        return new FactResult('instrument', __('Instrument').' '.$instrument->code, $instrument->status->getLabel(), $lines,
            [], null, note: $instrument->type->name ?? null);
    }

    /**
     * @return list<FactResult>
     */
    private function stock(User $user, ScanResult $result, bool $phone): array
    {
        if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
            return [$this->unit($user, $result->unit, $phone)];
        }

        if ($result->kind === ScanResultKind::LotSearch) {
            return $this->lot($user, $result);
        }

        if ($result->product === null) {
            return [];
        }

        $product = $result->product;
        $rows = StockOnHand::query()->with('branch')->where('product_id', $product->id)
            ->whereIn('branch_id', $this->visibleBranches($user, 'stock.view'))->get();

        $lines = $rows->filter(fn (StockOnHand $r) => $r->qty_total > 0)->map(fn (StockOnHand $r) => $this->line(
            $r->branch->code ?? '—',
            Display::number($r->qty_total).($r->qty_total !== $r->qty_shelf ? ' ('.__(':count on the shelf', ['count' => Display::number($r->qty_shelf)]).')' : ''),
        ))->values()->all();

        $first = $product->isSerialTracked()
            ? StockUnit::query()->onHand()->where('product_id', $product->id)->whereIn('branch_id', $this->visibleBranches($user, 'stock.view'))->min('expiry_date')
            : StockBatch::query()->onHand()->where('product_id', $product->id)->whereIn('branch_id', $this->visibleBranches($user, 'stock.view'))->min('expiry_date');

        if ($first !== null) {
            $lines[] = $this->line(__('First expiry'), Display::date($first));
        }

        $total = (int) $rows->sum('qty_total');
        $url = $phone || ! $user->canAccessAppPanel(AppPanel::Admin) ? route('ops.stock.product', $product) : Resources\Products\ProductResource::getUrl(Resources\Products\ProductResource::canEdit($product) ? 'edit' : 'index', Resources\Products\ProductResource::canEdit($product) ? ['record' => $product] : [], panel: 'admin');

        return [new FactResult('product', trim($product->sku.' · '.$product->name.' '.($product->size ?? '')), Display::number($total), $lines,
            ['on_hand' => $total], $user->can('stock.view') || $user->can('products.view_any') ? $url : null,
            $total > 0 ? FactResult::NEUTRAL : FactResult::WARNING, $total > 0 ? null : __('None on hand.'))];
    }

    private function unit(User $user, StockUnit $unit, bool $phone): FactResult
    {
        $unit->loadMissing(['product', 'branch', 'location', 'kit', 'customer']);
        $canSee = $user->canInBranch('stock.view', $unit->branch_id);
        $title = __('Serial').' '.$unit->serial;

        if (! $canSee) {
            return new FactResult('unit', $title, $unit->status->getLabel(), [$this->line(__('Station'), $unit->branch->code ?? '—')]);
        }

        $lines = [
            $this->line(__('Product'), trim($unit->product->sku.' · '.$unit->product->name)),
            $this->line(__('Where'), $unit->branch->code.' · '.$unit->locationLabel()),
        ];

        if ($unit->lot !== null) {
            $lines[] = $this->line(__('Lot'), $unit->lot, code: true);
        }

        if ($unit->expiry_date !== null) {
            $lines[] = $this->line(__('Expiry'), Display::date($unit->expiry_date));
        }

        foreach ($this->history->for($unit)->take(3) as $movement) {
            /** @var StockMovement $movement */
            $lines[] = $this->line(Display::date($movement->occurred_at).' · '.$movement->type->getLabel(), $this->history->describe($movement));
        }

        $url = $phone || ! $user->canAccessAppPanel(AppPanel::Admin) ? route('ops.stock.unit', $unit) : Resources\StockUnits\StockUnitResource::getUrl('view', ['record' => $unit], panel: 'admin');

        return new FactResult('unit', $title, $unit->status->getLabel(), $lines, [], $url,
            $unit->isBlockedByExpiry() ? FactResult::DANGER : FactResult::NEUTRAL);
    }

    /**
     * A lot number: the boxes and units with it, in the branches the person may see.
     *
     * @return list<FactResult>
     */
    private function lot(User $user, ScanResult $result): array
    {
        $lines = [];
        $batches = $result->batches->filter(fn (StockBatch $b) => $user->canInBranch('stock.view', $b->branch_id));
        $units = $result->units->filter(fn (StockUnit $u) => $user->canInBranch('stock.view', $u->branch_id));

        foreach ($batches->take(6) as $batch) {
            $lines[] = $this->line(($batch->product->sku ?? '—').' · '.$batch->branch->code, Display::number($batch->quantity));
        }

        foreach ($units->take(6) as $unit) {
            $lines[] = $this->line($unit->serial.' · '.$unit->branch->code, $unit->status->getLabel());
        }

        if ($lines === []) {
            return [];
        }

        $total = (int) $batches->sum('quantity') + $units->filter(fn (StockUnit $u) => $u->status->isOnHand())->count();

        return [new FactResult('lot', __('Lot').' '.$result->parsed->code, Display::number($total), array_slice($lines, 0, 8), ['on_hand' => $total])];
    }

    /**
     * @return list<int>
     */
    private function visibleBranches(User $user, string $permission): array
    {
        $ids = app(BranchScopeResolver::class)->branchIdsFor($user, $permission);

        return $ids ?? Branch::query()->pluck('id')->all();
    }

    /**
     * @return array{label: string, value: string, code?: bool}
     */
    private function line(string $label, string $value, bool $code = false): array
    {
        return $code ? ['label' => $label, 'value' => $value, 'code' => true] : ['label' => $label, 'value' => $value];
    }
}
