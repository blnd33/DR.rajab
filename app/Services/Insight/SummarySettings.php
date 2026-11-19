<?php

namespace App\Services\Insight;

use App\Models\Branch;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Validation\ValidationException;

/**
 * One person's morning-summary choices: on / off, the time (business time), which sections,
 * which branch (null = all of theirs) and whether to skip Fridays. Anything not chosen falls
 * back to the defaults in Settings.
 */
final class SummarySettings
{
    /**
     * @param  list<string>  $sections  FactSection values
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $time,
        public readonly array $sections,
        public readonly ?int $branchId,
        public readonly bool $skipFridays,
    ) {}

    public static function for(User $user): self
    {
        $settings = app(SettingsRepository::class);
        $own = $user->summary_settings ?? [];
        $time = (string) ($own['time'] ?? $settings->get('summary.time', '07:30'));
        $sections = $own['sections'] ?? $settings->get('summary.sections');

        return new self(
            (bool) ($own['enabled'] ?? true),
            self::isTime($time) ? $time : '07:30',
            self::sections(is_array($sections) ? $sections : null),
            isset($own['branch_id']) && $own['branch_id'] !== '' ? (int) $own['branch_id'] : null,
            (bool) ($own['skip_fridays'] ?? $settings->bool('summary.skip_fridays')),
        );
    }

    /**
     * Check and store a person's choices (from their profile).
     *
     * @param  array<string, mixed>  $data
     */
    public static function save(User $user, array $data): self
    {
        $time = trim((string) ($data['time'] ?? ''));

        if (! self::isTime($time)) {
            throw ValidationException::withMessages(['time' => __('Give the time as HH:MM, e.g. 07:30.')]);
        }

        $branchId = filled($data['branch_id'] ?? null) ? (int) $data['branch_id'] : null;

        if ($branchId !== null && ! array_key_exists($branchId, self::branchOptions($user))) {
            throw ValidationException::withMessages(['branch_id' => __('You cannot choose this station.')]);
        }

        $user->forceFill(['summary_settings' => [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'time' => $time,
            'sections' => self::sections(is_array($data['sections'] ?? null) ? $data['sections'] : []),
            'branch_id' => $branchId,
            'skip_fridays' => (bool) ($data['skip_fridays'] ?? false),
        ]])->save();

        return self::for($user);
    }

    public function wants(FactSection $section): bool
    {
        return in_array($section->value, $this->sections, true);
    }

    /**
     * @return array{enabled: bool, time: string, sections: list<string>, branch_id: ?int, skip_fridays: bool}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'time' => $this->time,
            'sections' => $this->sections,
            'branch_id' => $this->branchId,
            'skip_fridays' => $this->skipFridays,
        ];
    }

    /**
     * @return array<string, string> section value → label
     */
    public static function sectionOptions(): array
    {
        return collect(FactSection::cases())->mapWithKeys(fn (FactSection $s) => [$s->value => $s->label()])->all();
    }

    /**
     * Branches a person may narrow the summary to: every open branch for people who work in
     * all branches, otherwise their own.
     *
     * @return array<int, string> id → "CODE · name"
     */
    public static function branchOptions(User $user): array
    {
        $query = $user->isSuperAdmin() || $user->all_branches ? Branch::query()->active() : $user->branches()->active();

        return $query->orderBy('code')->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->code.' · '.$b->name])->all();
    }

    private static function isTime(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    /**
     * @param  array<mixed>|null  $chosen
     * @return list<string> known sections, in summary order (null = all)
     */
    private static function sections(?array $chosen): array
    {
        $all = array_map(fn (FactSection $s) => $s->value, FactSection::cases());

        return $chosen === null ? $all : array_values(array_intersect($all, array_map('strval', $chosen)));
    }
}
