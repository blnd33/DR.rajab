<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Key/value settings. Read through SettingsRepository (cached), never directly.
 */
class Setting extends Model
{
    use LogsActivity;

    protected $fillable = ['group', 'key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('settings')
            ->logOnly(['group', 'key', 'value'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return mb_substr("{$this->group}.{$this->key}", 0, 64);
    }
}
