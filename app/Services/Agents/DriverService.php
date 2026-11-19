<?php

namespace App\Services\Agents;

use App\Models\Driver;
use App\Models\DriverTrip;
use App\Models\User;
use App\Services\Money\FxService;
use App\Support\Locale\Display;
use App\Support\Plate;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drivers (with their car and Iraqi plate) and their trips: arrival date, received date and
 * what we paid (IQD, USD or EUR). The pay is recorded only — no cash box moves. A trip is
 * never deleted or its amount changed: a wrong trip is voided with a reason and entered again.
 */
class DriverService
{
    public function __construct(
        private readonly CompanyAgentService $agents,
        private readonly FxService $fx,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $by): Driver
    {
        $this->agents->authorize($by);

        return Driver::query()->create($this->clean($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Driver $driver, array $data, User $by): Driver
    {
        $this->agents->authorize($by);
        $driver->update($this->clean($data, $driver));

        return $driver->refresh();
    }

    /**
     * @param  array{arrived_on?: mixed, received_on?: mixed, amount?: mixed, currency?: mixed, notes?: mixed}  $data
     */
    public function addTrip(Driver $driver, array $data, User $by): DriverTrip
    {
        $this->agents->authorize($by);

        $arrived = $this->date($data['arrived_on'] ?? null, 'arrived_on') ?? throw ValidationException::withMessages(['arrived_on' => __('Enter the arrival date.')]);
        $received = $this->date($data['received_on'] ?? null, 'received_on');
        $this->checkDates($arrived, $received);

        try {
            $amount = BigDecimal::of(str_replace([',', ' '], '', (string) ($data['amount'] ?? '')))->toScale(4, RoundingMode::HalfUp);
        } catch (MathException) {
            throw ValidationException::withMessages(['amount' => __('":value" is not a valid amount.', ['value' => (string) ($data['amount'] ?? '')])]);
        }

        if ($amount->isNegative()) {
            throw ValidationException::withMessages(['amount' => __('The amount cannot be below zero.')]);
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));

        if (! in_array($currency, config('orthotrack.currencies'), true)) {
            throw ValidationException::withMessages(['currency' => __('Choose the currency.')]);
        }

        return DB::transaction(fn () => $driver->trips()->create([
            'arrived_on' => $arrived->toDateString(),
            'received_on' => $received?->toDateString(),
            'amount' => (string) $amount,
            'currency' => $currency,
            'fx_snapshot_id' => $this->fx->current()?->id,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ]));
    }

    /**
     * Change the dates or the note of a trip (not the amount: void it and enter it again).
     *
     * @param  array{arrived_on?: mixed, received_on?: mixed, notes?: mixed}  $data
     */
    public function updateTrip(DriverTrip $trip, array $data, User $by): DriverTrip
    {
        $this->agents->authorize($by);
        $this->assertNotVoided($trip);

        $arrived = $this->date($data['arrived_on'] ?? null, 'arrived_on') ?? throw ValidationException::withMessages(['arrived_on' => __('Enter the arrival date.')]);
        $received = $this->date($data['received_on'] ?? null, 'received_on');
        $this->checkDates($arrived, $received);

        $trip->update([
            'arrived_on' => $arrived->toDateString(),
            'received_on' => $received?->toDateString(),
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ]);

        return $trip->refresh();
    }

    public function markReceived(DriverTrip $trip, mixed $date, User $by): DriverTrip
    {
        return $this->updateTrip($trip, [
            'arrived_on' => $trip->arrived_on->toDateString(),
            'received_on' => $date,
            'notes' => $trip->notes,
        ], $by);
    }

    public function voidTrip(DriverTrip $trip, string $reason, User $by): DriverTrip
    {
        $this->agents->authorize($by);
        $this->assertNotVoided($trip);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Write why this trip is voided.')]);
        }

        $trip->update(['voided_at' => now(), 'voided_by' => $by->id, 'void_reason' => trim($reason)]);

        return $trip->refresh();
    }

    /**
     * What we paid a driver, one line per currency (voided trips left out).
     *
     * @return list<string>
     */
    public function paidTotals(Driver $driver): array
    {
        return $driver->trips()->counted()
            ->groupBy('currency')->selectRaw('currency, SUM(amount) AS total')->orderBy('currency')
            ->toBase()->get()
            ->map(fn ($row) => Display::money((string) $row->total, (string) $row->currency))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function clean(array $data, ?Driver $existing = null): array
    {
        $text = fn (string $key, int $max) => filled($data[$key] ?? null) ? mb_substr(trim((string) $data[$key]), 0, $max) : null;

        $name = $text('name', 150) ?? throw ValidationException::withMessages(['name' => __('Enter the driver\'s name.')]);
        $plate = Plate::normalize($data);
        $errors = Plate::errors($plate);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $name,
            'phone' => $text('phone', 32),
            'car_type' => $text('car_type', 120),
            'company_car' => (bool) ($data['company_car'] ?? false),
            ...$plate,
            'notes' => $text('notes', 5000),
            'is_active' => (bool) ($data['is_active'] ?? $existing->is_active ?? true),
        ];
    }

    private function date(mixed $value, string $field): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('Enter a valid date.')]);
        }
    }

    private function checkDates(Carbon $arrived, ?Carbon $received): void
    {
        if ($received !== null && $received->lt($arrived)) {
            throw ValidationException::withMessages(['received_on' => __('The received date cannot be before the arrival date.')]);
        }
    }

    private function assertNotVoided(DriverTrip $trip): void
    {
        if ($trip->isVoided()) {
            throw new AuthorizationException(__('This trip was voided.'));
        }
    }
}
