<?php

namespace App\Services\Expiry;

use App\Models\ExpiryLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the whole expiry level set (Module 6) and keeps it valid:
 *   • exactly one "Expired" level, rule fixed at ≤ 0 days, always blocks usage;
 *   • the other levels start at day 1, have no gaps and no overlaps;
 *   • the last level is open-ended ("366+").
 * Text color (black or white) is computed from the chosen color for readability.
 */
class ExpiryLevelService
{
    public const DARK_TEXT = '#111827';

    public const LIGHT_TEXT = '#FFFFFF';

    /**
     * Default levels from the brief.
     *
     * @return list<array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            ['name' => ['en' => 'Expired', 'ar' => 'منتهي الصلاحية', 'ckb' => 'بەسەرچوو'], 'min_days' => null, 'max_days' => 0, 'color' => '#111827', 'is_expired_level' => true, 'blocks_usage' => true, 'notify_on_enter' => true],
            ['name' => ['en' => 'Critical', 'ar' => 'حرج', 'ckb' => 'مەترسیدار'], 'min_days' => 1, 'max_days' => 90, 'color' => '#DC2626', 'is_expired_level' => false, 'blocks_usage' => false, 'notify_on_enter' => true],
            ['name' => ['en' => 'Warning', 'ar' => 'تحذير', 'ckb' => 'ئاگاداری'], 'min_days' => 91, 'max_days' => 180, 'color' => '#EA580C', 'is_expired_level' => false, 'blocks_usage' => false, 'notify_on_enter' => true],
            ['name' => ['en' => 'Attention', 'ar' => 'انتباه', 'ckb' => 'سەرنج'], 'min_days' => 181, 'max_days' => 365, 'color' => '#EAB308', 'is_expired_level' => false, 'blocks_usage' => false, 'notify_on_enter' => false],
            ['name' => ['en' => 'Good', 'ar' => 'جيد', 'ckb' => 'باش'], 'min_days' => 366, 'max_days' => null, 'color' => '#16A34A', 'is_expired_level' => false, 'blocks_usage' => false, 'notify_on_enter' => false],
        ];
    }

    /**
     * Replace the level set. Each item: id?, name (translations), min_days, max_days,
     * color, blocks_usage, notify_on_enter, is_expired_level.
     *
     * @param  list<array<string, mixed>>  $levels
     */
    public function saveAll(array $levels): void
    {
        $levels = $this->normalize($levels);
        $this->validate($levels);

        DB::transaction(function () use ($levels) {
            $keepIds = [];

            foreach ($levels as $sort => $data) {
                $level = isset($data['id'])
                    ? ExpiryLevel::query()->lockForUpdate()->find($data['id']) ?? new ExpiryLevel
                    : new ExpiryLevel;

                // The expired row is matched by its flag, never re-created.
                if ($data['is_expired_level']) {
                    $level = ExpiryLevel::query()->lockForUpdate()->where('is_expired_level', true)->first() ?? $level;
                }

                $level->fill([
                    'name' => $data['name'],
                    'min_days' => $data['min_days'],
                    'max_days' => $data['max_days'],
                    'color' => $data['color'],
                    'text_color' => self::textColorFor($data['color']),
                    'sort' => $sort,
                    'is_expired_level' => $data['is_expired_level'],
                    'blocks_usage' => $data['blocks_usage'],
                    'notify_on_enter' => $data['notify_on_enter'],
                ])->save();

                $keepIds[] = $level->id;
            }

            ExpiryLevel::query()
                ->whereNotIn('id', $keepIds)
                ->where('is_expired_level', false)
                ->get()
                ->each(fn (ExpiryLevel $level) => $level->delete());
        });

        app(ExpiryClassifier::class)->flush();
    }

    /**
     * Black or white text, whichever has more contrast with the background (WCAG luminance).
     */
    public static function textColorFor(string $hex): string
    {
        $luminance = self::luminance($hex);

        $contrastWithWhite = 1.05 / ($luminance + 0.05);
        $contrastWithDark = ($luminance + 0.05) / (self::luminance(self::DARK_TEXT) + 0.05);

        return $contrastWithDark >= $contrastWithWhite ? self::DARK_TEXT : self::LIGHT_TEXT;
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(fn (string $pair) => hexdec($pair) / 255, str_split($hex, 2));

        $linear = array_map(
            fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     * @return list<array<string, mixed>> expired first, then by min_days
     */
    private function normalize(array $levels): array
    {
        $normalized = array_map(function (array $level) {
            $isExpired = (bool) ($level['is_expired_level'] ?? false);

            return [
                'id' => $level['id'] ?? null,
                'name' => array_filter((array) ($level['name'] ?? []), fn ($v) => filled($v)),
                // The expired rule is fixed: ≤ 0 days, always blocks usage.
                'min_days' => $isExpired ? null : (filled($level['min_days'] ?? null) ? (int) $level['min_days'] : null),
                'max_days' => $isExpired ? 0 : (filled($level['max_days'] ?? null) ? (int) $level['max_days'] : null),
                'color' => strtoupper(trim((string) ($level['color'] ?? ''))),
                'is_expired_level' => $isExpired,
                'blocks_usage' => $isExpired || (bool) ($level['blocks_usage'] ?? false),
                'notify_on_enter' => (bool) ($level['notify_on_enter'] ?? false),
            ];
        }, array_values($levels));

        usort($normalized, function (array $a, array $b) {
            if ($a['is_expired_level'] !== $b['is_expired_level']) {
                return $a['is_expired_level'] ? -1 : 1;
            }

            return ($a['min_days'] ?? PHP_INT_MIN) <=> ($b['min_days'] ?? PHP_INT_MIN);
        });

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $levels
     */
    private function validate(array $levels): void
    {
        $errors = [];
        $expired = array_values(array_filter($levels, fn ($l) => $l['is_expired_level']));
        $others = array_values(array_filter($levels, fn ($l) => ! $l['is_expired_level']));

        if (count($expired) !== 1) {
            $errors['levels'][] = __('There must be exactly one "Expired" level.');
        }

        if ($others === []) {
            $errors['levels'][] = __('Add at least one level after "Expired".');
        }

        foreach ($levels as $index => $level) {
            $position = $index + 1;

            if (blank($level['name']['en'] ?? null)) {
                $errors['levels'][] = __('Level :n: the English name is required.', ['n' => $position]);
            }

            if (! preg_match('/^#[0-9A-F]{6}$/', $level['color'])) {
                $errors['levels'][] = __('Level :n: choose a color.', ['n' => $position]);
            }
        }

        $expectedMin = 1;

        foreach ($others as $index => $level) {
            $label = $level['name']['en'] ?? (string) ($index + 1);
            $isLast = $index === count($others) - 1;

            if ($level['min_days'] === null) {
                $errors['levels'][] = __('":level": enter the first day.', ['level' => $label]);

                continue;
            }

            if ($level['min_days'] < $expectedMin) {
                $errors['levels'][] = __('":level" overlaps the level before it (it should start at day :day).', ['level' => $label, 'day' => $expectedMin]);
            } elseif ($level['min_days'] > $expectedMin) {
                $errors['levels'][] = __('There is a gap before ":level": days :from–:to are not in any level.', ['level' => $label, 'from' => $expectedMin, 'to' => $level['min_days'] - 1]);
            }

            if ($isLast) {
                if ($level['max_days'] !== null) {
                    $errors['levels'][] = __('The last level (":level") must have no end ("and more").', ['level' => $label]);
                }

                continue;
            }

            if ($level['max_days'] === null) {
                $errors['levels'][] = __('Only the last level can be open-ended. ":level" needs a last day.', ['level' => $label]);

                break;
            }

            if ($level['max_days'] < $level['min_days']) {
                $errors['levels'][] = __('":level": the last day is before the first day.', ['level' => $label]);
            }

            $expectedMin = $level['max_days'] + 1;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
