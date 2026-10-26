<?php

namespace App\Http\Controllers\Ops;

use App\Exceptions\Domain\StockRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\StockCount;
use App\Models\User;
use App\Services\Movement\StockCountService;
use App\Support\DeviceLabel;
use App\Support\Locale\DigitConverter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Stock count scans queued on a phone (IndexedDB) while offline. Every scan carries a
 * client_uuid; replays are recognised by StockCountService, so nothing is counted twice.
 * Response: which uuids are done (remove from the phone's queue) and how each resolved.
 */
class CountScanSyncController extends Controller
{
    public function __invoke(Request $request, StockCountService $counts): JsonResponse
    {
        $data = $request->validate([
            'scans' => ['required', 'array', 'max:500'],
            'scans.*.client_uuid' => ['required', 'uuid'],
            'scans.*.count_id' => ['required', 'integer'],
            'scans.*.raw' => ['required', 'string', 'max:1000'],
            'scans.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'scans.*.scanned_at' => ['nullable', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $device = DeviceLabel::fromUserAgent($request->userAgent());
        $results = [];

        foreach ($data['scans'] as $scan) {
            $count = StockCount::query()->find($scan['count_id']);

            try {
                if ($count === null) {
                    throw new StockRuleViolation(__('This stock count no longer exists.'));
                }

                $line = $counts->recordScan(
                    $count,
                    $scan['client_uuid'],
                    (string) DigitConverter::toWestern($scan['raw']),
                    $user,
                    (int) ($scan['quantity'] ?? 1),
                    isset($scan['scanned_at']) ? Carbon::parse($scan['scanned_at']) : null,
                    $device,
                );

                $results[] = [
                    'client_uuid' => $scan['client_uuid'],
                    'ok' => $line !== null,
                    'result' => $line?->result->value ?? ($line !== null ? 'counted' : 'unknown'),
                    'message' => $line === null ? __('Code not recognised.') : null,
                ];
            } catch (StockRuleViolation|AuthorizationException $e) {
                // Rejected for good (count closed, not allowed): drop it from the queue.
                $results[] = ['client_uuid' => $scan['client_uuid'], 'ok' => false, 'result' => 'rejected', 'message' => $e->getMessage()];
            }
        }

        return response()->json([
            'accepted' => array_column($results, 'client_uuid'),
            'results' => $results,
        ]);
    }
}
