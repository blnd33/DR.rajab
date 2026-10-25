<?php

namespace App\Models;

use App\Enums\LabelSymbology;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Thermal label layout. layout = {
 *   "fields": ["name", "sku", "size", "serial", "lot", "expiry"],
 *   "font_pt": 7, "show_human_readable": true
 * }
 *
 * @property int $id
 * @property string $name
 * @property string $width_mm
 * @property string $height_mm
 * @property LabelSymbology $symbology
 * @property array<string, mixed> $layout
 * @property bool $is_default
 */
class LabelTemplate extends Model
{
    use LogsActivity;
    use TracksAuthor;

    /** Fields a label can show, in print order. */
    public const FIELDS = ['name', 'sku', 'size', 'serial', 'lot', 'expiry', 'branch'];

    protected $fillable = ['name', 'width_mm', 'height_mm', 'symbology', 'layout', 'is_default'];

    protected function casts(): array
    {
        return [
            'width_mm' => 'decimal:2',
            'height_mm' => 'decimal:2',
            'symbology' => LabelSymbology::class,
            'layout' => 'array',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Only one default (a partial unique index enforces it too).
        static::saving(function (LabelTemplate $template) {
            if ($template->is_default) {
                static::query()->whereKeyNot($template->id ?? 0)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('settings')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = $this->layout['fields'] ?? ['name', 'sku'];

        return array_values(array_intersect(self::FIELDS, $fields));
    }

    public function fontPt(): float
    {
        return (float) ($this->layout['font_pt'] ?? 7);
    }

    public function sizeLabel(): string
    {
        return rtrim(rtrim($this->width_mm, '0'), '.').' × '.rtrim(rtrim($this->height_mm, '0'), '.').' mm';
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first() ?? static::query()->orderBy('id')->first();
    }
}
