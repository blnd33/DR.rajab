<?php

namespace App\Livewire\Ops\Clinical;

use App\Enums\Gender;
use App\Enums\KitCheckoutStatus;
use App\Enums\Laterality;
use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\Customer;
use App\Models\KitCheckout;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Models\User;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Phone surgery form (P3-4). Everything happens in the browser (Alpine + IndexedDB) so a
 * dropped connection loses nothing; this component only hands over the lists and, when
 * editing a server draft (?surgery=), its current values.
 */
#[Layout('layouts::ops')]
class SurgeryCreate extends Component
{
    use RunsStockActions;

    #[Url]
    public ?int $surgery = null;

    public function mount(): void
    {
        abort_unless($this->user()->can('create', Surgery::class), 403);
    }

    public function render(): View
    {
        $branchId = (int) CurrentBranch::id();

        return view('livewire.ops.clinical.surgery-create', [
            'branch' => CurrentBranch::get(),
            'canHere' => $branchId !== 0 && $this->user()->canInBranch('surgeries.create', $branchId),
            'initial' => $this->initial($branchId),
            'types' => SurgeryType::options(),
            'hospitals' => Customer::options(),
            'doctors' => User::doctorOptions($branchId ?: null),
            'technicians' => User::technicianOptions($branchId ?: null),
            'currencies' => config('orthotrack.currencies'),
            'checkouts' => KitCheckout::query()->with(['kit', 'customer'])->where('branch_id', $branchId)->where('status', KitCheckoutStatus::Out)->get()
                ->mapWithKeys(fn (KitCheckout $c) => [$c->id => $c->kit->code.' · '.$c->customer->code])->all(),
            'lateralities' => Laterality::options(),
            'genders' => Gender::options(),
        ])->title(__('New surgery'));
    }

    /**
     * @return array<string, mixed>
     */
    private function initial(int $branchId): array
    {
        $draft = $this->surgery !== null ? Surgery::query()->with(['patient', 'doctors', 'items.stockUnit', 'items.stockBatch', 'items.product'])->find($this->surgery) : null;

        if ($draft === null || $draft->status !== SurgeryStatus::Draft || ! $this->user()->can('view', $draft)) {
            return [
                'branch_id' => $branchId,
                'surgery_date' => now(config('orthotrack.timezone'))->toDateString(),
                'laterality' => Laterality::NotApplicable->value,
                'lead_doctor_id' => $this->user()->id,
                'technician_ids' => [],
                'fee_amount' => '',
                'fee_currency' => CurrentBranch::get()->default_currency ?? 'USD',
                'items' => [],
            ];
        }

        return [
            'client_uuid' => $draft->client_uuid,
            'branch_id' => $draft->branch_id,
            'surgery_date' => $draft->surgery_date->toDateString(),
            'surgery_type_id' => $draft->surgery_type_id,
            'customer_id' => $draft->customer_id,
            'patient_id' => $draft->patient_id,
            'patient_label' => $draft->patient?->label(),
            'new_patient' => $draft->patient_details ?? ['full_name' => '', 'phone' => '', 'age' => null, 'gender' => ''],
            'laterality' => $draft->laterality->value,
            'kit_checkout_id' => $draft->kit_checkout_id,
            'lead_doctor_id' => $draft->leadDoctor()->id ?? $this->user()->id,
            'assistant_ids' => $draft->doctors->filter(fn (User $d) => $d->getRelationValue('pivot')?->getAttribute('role') === SurgeryDoctorRole::Assistant->value)->pluck('id')->values()->all(),
            'technician_ids' => $draft->doctors->filter(fn (User $d) => $d->getRelationValue('pivot')?->getAttribute('role') === SurgeryDoctorRole::Technician->value)->pluck('id')->values()->all(),
            'fee_amount' => $draft->fee_amount !== null ? (string) (float) $draft->fee_amount : '',
            'fee_currency' => $draft->fee_currency ?? ($draft->branch->default_currency ?? 'USD'),
            'notes' => $draft->notes,
            'items' => $draft->items->map(fn ($i) => [
                'raw' => $i->scanned_raw ?: ($i->stockUnit->serial ?? ''),
                'stock_batch_id' => blank($i->scanned_raw) && $i->stock_batch_id !== null ? $i->stock_batch_id : null,
                'label' => $i->stockBatch !== null ? trim(($i->stockBatch->lot ?? '').' · '.$i->product->sku) : null,
                'quantity' => $i->quantity,
            ])->values()->all(),
            'problems' => $draft->problems ?? [],
        ];
    }
}
