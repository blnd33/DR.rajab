<?php

namespace App\Models;

use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Audit log entry. Append-only: a database trigger rejects UPDATE/DELETE,
 * including for the Super Admin.
 *
 * @property ?int $branch_id
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property ?string $reference_code
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (Activity $activity) {
            if (app()->runningInConsole() === false || app()->runningUnitTests()) {
                $request = request();
                $activity->ip_address ??= $request->ip();
                $activity->user_agent ??= mb_substr((string) $request->userAgent(), 0, 1000) ?: null;
            }

            $activity->branch_id ??= self::branchIdFromSubject($activity) ?? CurrentBranch::id();
            $activity->reference_code ??= self::referenceCodeFromSubject($activity);
        });
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    private static function branchIdFromSubject(Activity $activity): ?int
    {
        $subject = $activity->subject;

        if ($subject instanceof Branch) {
            return $subject->getKey();
        }

        // Read raw attributes: strict mode throws on getAttribute() for columns a model doesn't have.
        $branchId = $subject?->getAttributes()['branch_id'] ?? null;

        return is_numeric($branchId) ? (int) $branchId : null;
    }

    /**
     * Subjects expose a searchable code (serial, number, code…) via activityReferenceCode().
     */
    private static function referenceCodeFromSubject(Activity $activity): ?string
    {
        $subject = $activity->subject;

        if ($subject !== null && method_exists($subject, 'activityReferenceCode')) {
            return $subject->activityReferenceCode();
        }

        $attributes = $subject?->getAttributes() ?? [];

        foreach (['serial', 'number', 'code', 'username'] as $attribute) {
            $value = $attributes[$attribute] ?? null;

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 64);
            }
        }

        return null;
    }
}
