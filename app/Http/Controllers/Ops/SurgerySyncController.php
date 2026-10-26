<?php

namespace App\Http\Controllers\Ops;

use App\Exceptions\Domain\StockRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Clinical\SurgeryService;
use App\Support\Locale\DigitConverter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Surgery drafts saved on a phone (IndexedDB) while offline (P3-4). Each draft carries a
 * client_uuid; SurgeryService updates the same draft on every send and ignores replays of a
 * completed one. Response: which drafts are done (remove from the phone) and their state.
 */
class SurgerySyncController extends Controller
{
    public function __invoke(Request $request, SurgeryService $surgeries): JsonResponse
    {
        $data = $request->validate([
            'drafts' => ['required', 'array', 'max:20'],
            'drafts.*.client_uuid' => ['required', 'uuid'],
            'drafts.*.branch_id' => ['required', 'integer'],
            'drafts.*.surgery_date' => ['nullable', 'date'],
            'drafts.*.surgery_type_id' => ['nullable', 'integer'],
            'drafts.*.customer_id' => ['nullable', 'integer'],
            'drafts.*.patient_id' => ['nullable', 'integer'],
            'drafts.*.new_patient' => ['nullable', 'array'],
            'drafts.*.new_patient.full_name' => ['nullable', 'string', 'max:150'],
            'drafts.*.new_patient.phone' => ['nullable', 'string', 'max:32'],
            'drafts.*.new_patient.age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'drafts.*.new_patient.gender' => ['nullable', 'in:male,female'],
            'drafts.*.laterality' => ['nullable', 'string', 'max:16'],
            'drafts.*.kit_checkout_id' => ['nullable', 'integer'],
            'drafts.*.lead_doctor_id' => ['nullable', 'integer'],
            'drafts.*.assistant_ids' => ['nullable', 'array'],
            'drafts.*.notes' => ['nullable', 'string', 'max:5000'],
            'drafts.*.override_reason' => ['nullable', 'string', 'max:500'],
            'drafts.*.complete' => ['nullable', 'boolean'],
            'drafts.*.items' => ['nullable', 'array', 'max:100'],
            'drafts.*.items.*.raw' => ['nullable', 'string', 'max:1000'],
            'drafts.*.items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'drafts.*.items.*.product_id' => ['nullable', 'integer'],
            'drafts.*.items.*.stock_batch_id' => ['nullable', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $results = [];

        foreach ($data['drafts'] as $draft) {
            foreach ($draft['items'] ?? [] as $i => $item) {
                $draft['items'][$i]['raw'] = (string) DigitConverter::toWestern($item['raw'] ?? '');
            }

            try {
                $surgery = $surgeries->sync($draft, $user);

                $results[] = [
                    'client_uuid' => $draft['client_uuid'],
                    'ok' => true,
                    'id' => $surgery->id,
                    'number' => $surgery->number,
                    'status' => $surgery->status->value,
                    'problems' => $surgery->problems ?? [],
                    'url' => route('ops.surgeries.show', $surgery),
                ];
            } catch (StockRuleViolation|AuthorizationException $e) {
                // Kept on the server side as nothing: the phone shows the reason and drops it.
                $results[] = ['client_uuid' => $draft['client_uuid'], 'ok' => false, 'status' => 'rejected', 'problems' => [$e->getMessage()]];
            }
        }

        return response()->json([
            'accepted' => array_column($results, 'client_uuid'),
            'results' => $results,
        ]);
    }
}
