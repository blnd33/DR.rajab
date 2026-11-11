<?php

namespace App\Services\Insight;

use App\Models\MorningSummary;
use App\Models\User;
use App\Notifications\MorningSummaryReady;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sends each person their morning summary once a day, when their time has come, and keeps
 * every sent summary so any past morning can be opened again.
 */
class MorningSummaryService
{
    public function __construct(private readonly MorningSummaryBuilder $builder) {}

    public static function canReceive(User $user): bool
    {
        return $user->is_active && $user->can('summary.receive');
    }

    /**
     * Today's morning in business time (YYYY-MM-DD).
     */
    public static function today(): string
    {
        return now(config('orthotrack.timezone'))->toDateString();
    }

    /**
     * Should this person get today's summary now? (On, their time has come, not a skipped
     * Friday, not sent yet today.)
     */
    public function isDue(User $user, ?CarbonInterface $now = null): bool
    {
        $now = ($now ?? now())->copy()->setTimezone(config('orthotrack.timezone'));
        $settings = SummarySettings::for($user);

        if (! self::canReceive($user) || ! $settings->enabled) {
            return false;
        }

        if ($settings->skipFridays && $now->isFriday()) {
            return false;
        }

        if ($now->format('H:i') < $settings->time) {
            return false;
        }

        return ! MorningSummary::query()->where('user_id', $user->id)
            ->whereDate('summary_date', $now->toDateString())->whereNotNull('sent_at')->exists();
    }

    /**
     * Send every summary that is due. Returns how many were sent.
     */
    public function sendDue(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $sent = 0;

        foreach ($this->receivers() as $user) {
            if ($this->isDue($user, $now)) {
                $this->send($user, $now->copy()->setTimezone(config('orthotrack.timezone'))->toDateString());
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Build, keep and send one person's summary for a morning (again, if it was sent before).
     */
    public function send(User $user, ?string $date = null): MorningSummary
    {
        $date ??= self::today();
        $payload = $this->builder->build($user, $date);

        $summary = DB::transaction(function () use ($user, $date, $payload) {
            $summary = MorningSummary::query()->where('user_id', $user->id)->whereDate('summary_date', $date)->lockForUpdate()->first()
                ?? new MorningSummary(['user_id' => $user->id, 'summary_date' => $date]);
            $summary->fill(['payload' => $payload, 'sent_at' => now()])->save();

            return $summary;
        });

        $user->notify(new MorningSummaryReady($summary->id));

        return $summary;
    }

    /**
     * The summary of a morning for the summary page: as it was sent, or — for a morning
     * nothing was sent (today before the time, or before the summary existed) — built now.
     *
     * @return array{payload: array<string, mixed>, sent_at: ?Carbon, live: bool}
     */
    public function forDate(User $user, string $date): array
    {
        $saved = MorningSummary::query()->where('user_id', $user->id)->whereDate('summary_date', $date)->first();

        if ($saved !== null && $saved->sent_at !== null) {
            return ['payload' => $saved->payload, 'sent_at' => $saved->sent_at, 'live' => false];
        }

        return ['payload' => $this->builder->build($user, $date), 'sent_at' => null, 'live' => true];
    }

    /**
     * @return Collection<int, User>
     */
    private function receivers(): Collection
    {
        return User::query()->where('is_active', true)->get()->filter(fn (User $u) => self::canReceive($u))->values();
    }
}
