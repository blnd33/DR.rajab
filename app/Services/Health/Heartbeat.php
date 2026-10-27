<?php

namespace App\Services\Health;

use App\Models\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Event;

/**
 * Records when each scheduled job last started and finished (P5-6), so the health page can
 * show a job that stopped running.
 */
class Heartbeat
{
    /**
     * Expected jobs and how many hours may pass between two runs before they count as late.
     *
     * @var array<string, int>
     */
    public const JOBS = [
        'backup:run' => 26,
        'backup:monitor' => 26,
        'stock:alerts' => 26,
        'money:daily' => 26,
        'reports:snapshot' => 26,
    ];

    /**
     * Attach start / finish recording to a scheduled event.
     */
    public static function track(Event $event, string $name): Event
    {
        return $event
            ->before(fn () => self::started($name))
            ->onSuccess(fn () => self::finished($name, 'ok'))
            ->onFailure(fn () => self::finished($name, 'failed'));
    }

    public static function started(string $name): void
    {
        SchedulerHeartbeat::query()->updateOrCreate(['name' => $name], ['last_started_at' => now()]);
    }

    public static function finished(string $name, string $status, ?string $output = null): void
    {
        SchedulerHeartbeat::query()->updateOrCreate(['name' => $name], [
            'last_finished_at' => now(),
            'last_status' => $status,
            'last_output' => $output !== null ? mb_substr($output, 0, 2000) : null,
        ]);
    }
}
