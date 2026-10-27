<?php

namespace App\Services\Health;

use App\Enums\RateStatus;
use App\Models\ExchangeRate;
use App\Models\SchedulerHeartbeat;
use App\Support\Locale\Display;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Config\Config as BackupConfig;
use Throwable;

/**
 * What the Super Admin and Coda need to know about the system (P5-6): backups, scheduled
 * jobs, queue, disk, exchange-rate age, versions. Each check is ok / warning / critical.
 */
class SystemHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    /**
     * @return list<array{key: string, group: string, label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        return [
            ...$this->backups(),
            ...$this->jobs(),
            $this->queue(),
            $this->disk(),
            ...$this->rates(),
        ];
    }

    /**
     * Checks that are not OK. Cached for five minutes (the dashboard and the menu badge ask on
     * every page; the backup check reaches the backup storage over the network).
     *
     * @return list<array{key: string, group: string, label: string, status: string, detail: string}>
     */
    public function problems(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('system-health.problems');
        }

        return Cache::remember('system-health.problems', 300, fn () => array_values(array_filter($this->checks(), fn (array $c) => $c['status'] !== self::OK)));
    }

    /**
     * @return array<string, string>
     */
    public function versions(): array
    {
        $driver = DB::connection()->getDriverName();

        try {
            $server = (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            $server = '—';
        }

        return [
            __('Application') => (string) config('orthotrack.version', 'dev'),
            'Laravel' => app()->version(),
            'PHP' => PHP_VERSION,
            __('Database') => $driver.' '.$server,
            __('Queue') => (string) config('queue.default'),
            __('Time zone') => (string) config('orthotrack.timezone'),
        ];
    }

    /**
     * @return list<array{key: string, group: string, label: string, status: string, detail: string}>
     */
    private function backups(): array
    {
        try {
            $destinations = BackupDestinationFactory::createFromArray(BackupConfig::fromArray(config('backup')));
        } catch (Throwable $e) {
            return [$this->check('backup', __('Backups'), __('Backups'), self::CRITICAL, __('Backup settings are not valid: :error', ['error' => $e->getMessage()]))];
        }

        $checks = [];

        foreach ($destinations as $destination) {
            $label = __('Backups on :disk', ['disk' => $destination->diskName()]);

            try {
                if (! $destination->isReachable()) {
                    $checks[] = $this->check('backup-'.$destination->diskName(), __('Backups'), $label, self::CRITICAL, __('The backup storage can\'t be reached.'));

                    continue;
                }

                $newest = $destination->newestBackup();
            } catch (Throwable $e) {
                $checks[] = $this->check('backup-'.$destination->diskName(), __('Backups'), $label, self::CRITICAL, $e->getMessage());

                continue;
            }

            $checks[] = match (true) {
                $newest === null => $this->check('backup-'.$destination->diskName(), __('Backups'), $label, self::CRITICAL, __('No backup yet.')),
                $newest->date()->lt(now()->subHours(26)) => $this->check('backup-'.$destination->diskName(), __('Backups'), $label, self::CRITICAL,
                    __('Last backup :date — more than a day ago.', ['date' => Display::dateTime($newest->date())])),
                default => $this->check('backup-'.$destination->diskName(), __('Backups'), $label, self::OK,
                    __('Last backup :date (:size MB).', ['date' => Display::dateTime($newest->date()), 'size' => Display::number($newest->sizeInBytes() / 1048576, 1)])),
            };
        }

        return $checks;
    }

    /**
     * @return list<array{key: string, group: string, label: string, status: string, detail: string}>
     */
    private function jobs(): array
    {
        $beats = SchedulerHeartbeat::query()->get()->keyBy('name');
        $checks = [];

        foreach (Heartbeat::JOBS as $name => $hours) {
            $beat = $beats->get($name);
            $label = __('Daily job :name', ['name' => $name]);

            $checks[] = match (true) {
                $beat === null || $beat->last_finished_at === null => $this->check('job-'.$name, __('Scheduled jobs'), $label, self::WARNING, __('Has not run yet.')),
                $beat->last_status === 'failed' => $this->check('job-'.$name, __('Scheduled jobs'), $label, self::CRITICAL, __('Failed on :date.', ['date' => Display::dateTime($beat->last_finished_at)])),
                $beat->last_finished_at->lt(now()->subHours($hours)) => $this->check('job-'.$name, __('Scheduled jobs'), $label, self::CRITICAL,
                    __('Last ran :date — the scheduler may be stopped.', ['date' => Display::dateTime($beat->last_finished_at)])),
                default => $this->check('job-'.$name, __('Scheduled jobs'), $label, self::OK, __('Last ran :date.', ['date' => Display::dateTime($beat->last_finished_at)])),
            };
        }

        return $checks;
    }

    /**
     * @return array{key: string, group: string, label: string, status: string, detail: string}
     */
    private function queue(): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $waiting = config('queue.default') === 'database' && Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $detail = __(':failed failed jobs', ['failed' => Display::number($failed)]).($waiting !== null ? ' · '.__(':waiting waiting', ['waiting' => Display::number($waiting)]) : '');

        return $this->check('queue', __('Queue'), __('Background jobs'), $failed > 0 ? self::WARNING : self::OK, $detail);
    }

    /**
     * @return array{key: string, group: string, label: string, status: string, detail: string}
     */
    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());

        if ($free === false || $total === false || $total <= 0) {
            return $this->check('disk', __('Server'), __('Disk space'), self::WARNING, __('Unknown.'));
        }

        $share = $free / $total * 100;
        $detail = __(':free GB free of :total GB (:percent%)', [
            'free' => Display::number($free / 1073741824, 1),
            'total' => Display::number($total / 1073741824, 1),
            'percent' => Display::number($share, 0),
        ]);

        return $this->check('disk', __('Server'), __('Disk space'), match (true) {
            $share < 10 => self::CRITICAL,
            $share < 20 => self::WARNING,
            default => self::OK,
        }, $detail);
    }

    /**
     * @return list<array{key: string, group: string, label: string, status: string, detail: string}>
     */
    private function rates(): array
    {
        $checks = [];

        foreach ([['USD', 'IQD'], ['EUR', 'USD']] as [$base, $quote]) {
            $rate = ExchangeRate::query()->where('base_currency', $base)->where('quote_currency', $quote)
                ->where('status', RateStatus::Confirmed)->latest('effective_at')->first();
            $label = __('Exchange rate :pair', ['pair' => "{$base}/{$quote}"]);

            $checks[] = match (true) {
                $rate === null => $this->check("rate-{$base}{$quote}", __('Money'), $label, self::CRITICAL, __('Not set — money screens are blocked.')),
                $rate->effective_at->lt(now()->subDays(7)) => $this->check("rate-{$base}{$quote}", __('Money'), $label, self::WARNING,
                    __(':rate since :date — more than a week old.', ['rate' => Display::number($rate->rate, 4), 'date' => Display::date($rate->effective_at)])),
                default => $this->check("rate-{$base}{$quote}", __('Money'), $label, self::OK,
                    __(':rate since :date.', ['rate' => Display::number($rate->rate, 4), 'date' => Display::date($rate->effective_at)])),
            };
        }

        return $checks;
    }

    /**
     * @return array{key: string, group: string, label: string, status: string, detail: string}
     */
    private function check(string $key, string $group, string $label, string $status, string $detail): array
    {
        return compact('key', 'group', 'label', 'status', 'detail');
    }
}
