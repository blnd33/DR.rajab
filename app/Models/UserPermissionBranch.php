<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Permission;

/**
 * Branch scope override for one permission of one user. branch_id NULL = all branches.
 *
 * @property int $user_id
 * @property int $permission_id
 * @property ?int $branch_id
 */
class UserPermissionBranch extends Model
{
    use LogsActivity;

    protected $fillable = ['user_id', 'permission_id', 'branch_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('permissions')
            ->logOnly(['user_id', 'permission_id', 'branch_id']);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Permission, $this> */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
