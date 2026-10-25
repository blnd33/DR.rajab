<?php

namespace App\Models;

use App\Models\Concerns\HasCleanTranslations;
use App\Models\Concerns\TracksAuthor;
use App\Support\Locale\Display;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One configurable expiry level (Module 6). Saved as a whole set through ExpiryLevelService.
 *
 * @property int $id
 * @property string $name
 * @property ?int $min_days
 * @property ?int $max_days
 * @property string $color
 * @property string $text_color
 * @property int $sort
 * @property bool $is_expired_level
 * @property bool $blocks_usage
 * @property bool $notify_on_enter
 */
class ExpiryLevel extends Model
{
    use HasCleanTranslations;
    use LogsActivity;
    use TracksAuthor;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['name', 'min_days', 'max_days', 'color', 'text_color', 'sort', 'is_expired_level', 'blocks_usage', 'notify_on_enter'];

    protected function casts(): array
    {
        return [
            'min_days' => 'integer',
            'max_days' => 'integer',
            'sort' => 'integer',
            'is_expired_level' => 'boolean',
            'blocks_usage' => 'boolean',
            'notify_on_enter' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('settings')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function contains(int $daysLeft): bool
    {
        return ($this->min_days === null || $daysLeft >= $this->min_days)
            && ($this->max_days === null || $daysLeft <= $this->max_days);
    }

    /**
     * "≤ 0", "1–90", "366+" (digits in the user's language).
     */
    public function rangeLabel(): string
    {
        return match (true) {
            $this->min_days === null => '≤ '.Display::number($this->max_days),
            $this->max_days === null => Display::number($this->min_days).'+',
            default => Display::number($this->min_days).'–'.Display::number($this->max_days),
        };
    }
}
