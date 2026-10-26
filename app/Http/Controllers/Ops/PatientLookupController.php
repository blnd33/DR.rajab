<?php

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\User;
use App\Services\Clinical\PatientService;
use App\Support\Locale\Display;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patient search for the phone surgery form: only patients the user may see.
 */
class PatientLookupController extends Controller
{
    public function __invoke(Request $request, PatientService $patients): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $term = trim((string) $request->query('q', ''));

        abort_unless($user->can('viewAny', Patient::class), 403);

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        return response()->json($patients->search($user, $term)->orderBy('full_name')->limit(20)->get()
            ->map(fn (Patient $p) => ['id' => $p->id, 'label' => $p->label(), 'age' => $p->age() !== null ? Display::number($p->age()) : null])
            ->values());
    }
}
