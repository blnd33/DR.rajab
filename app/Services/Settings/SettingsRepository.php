<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Typed access to the `settings` table with defaults from config/orthotrack.php.
 * All values are cached together; any write clears the cache.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'orthotrack.settings.v1';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->values();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        // Keys contain dots, so read the defaults array directly (config() would treat them as nesting).
        $defaults = $this->defaults();

        return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * @param  array<string, mixed>  $values  "group.key" => value
     */
    public function setMany(array $values): void
    {
        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                [$group, $name] = $this->split($key);

                Setting::query()->updateOrCreate(
                    ['group' => $group, 'key' => $name],
                    ['value' => $value, 'updated_by' => Auth::id()],
                );
            }
        });

        $this->flush();
    }

    /**
     * Every key under a group, merged over defaults ("company" → ['name' => …]).
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $result = [];

        foreach (array_keys($this->defaults()) as $key) {
            if (str_starts_with($key, "{$group}.")) {
                $result[substr($key, strlen($group) + 1)] = $this->get($key);
            }
        }

        foreach ($this->values() as $key => $value) {
            if (str_starts_with($key, "{$group}.")) {
                $result[substr($key, strlen($group) + 1)] = $value;
            }
        }

        return $result;
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return config('orthotrack.settings_defaults', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            return $this->loaded = Cache::rememberForever(self::CACHE_KEY, function () {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                return Setting::query()->get(['group', 'key', 'value'])
                    ->mapWithKeys(fn (Setting $s) => ["{$s->group}.{$s->key}" => $s->value])
                    ->all();
            });
        } catch (Throwable) {
            // Database not reachable yet (install, config:cache on a fresh box): use defaults.
            return [];
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $key): array
    {
        $position = strpos($key, '.');

        if ($position === false || $position === 0) {
            throw new InvalidArgumentException("Setting key [{$key}] must be \"group.key\".");
        }

        return [substr($key, 0, $position), substr($key, $position + 1)];
    }
}
