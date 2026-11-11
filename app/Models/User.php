<?php

namespace App\Models;

use App\Enums\Panel as AppPanel;
use App\Enums\Role;
use App\Services\Permissions\BranchScopeResolver;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property ?string $email
 * @property string $locale
 * @property bool $all_branches
 * @property ?int $primary_branch_id
 * @property bool $is_active
 * @property ?array<string, mixed> $summary_settings
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPushSubscriptions;
    use HasRoles;
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use LogsActivity;
    use Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'locale',
        'all_branches',
        'primary_branch_id',
        'is_active',
        'session_timeout_minutes',
        'summary_settings',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'all_branches' => 'boolean',
            'is_active' => 'boolean',
            'session_timeout_minutes' => 'integer',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'summary_settings' => 'array',
        ];
    }

    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => blank($value) ? null : mb_strtolower(trim($value)));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly([
                'name', 'username', 'email', 'phone', 'locale', 'all_branches',
                'primary_branch_id', 'is_active', 'session_timeout_minutes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('created_at');
    }

    /** @return BelongsTo<Branch, $this> */
    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    /** @return HasMany<UserSession, $this> */
    public function deviceSessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /** @return HasMany<UserPermissionBranch, $this> */
    public function permissionBranchOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionBranch::class);
    }

    /** @return HasMany<LoginAttempt, $this> */
    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** @param Builder<User> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Roles, panels, branch scope
    |--------------------------------------------------------------------------
    */

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SuperAdmin->value);
    }

    /**
     * Active users who may log surgeries (for doctor pickers).
     *
     * @return array<int, string>
     */
    public static function doctorOptions(): array
    {
        return static::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->permission('surgeries.create')->orWhereHas('roles', fn ($r) => $r->where('name', Role::SuperAdmin->value)))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function primaryRole(): ?Role
    {
        foreach (Role::cases() as $role) {
            if ($this->hasRole($role->value)) {
                return $role;
            }
        }

        return null;
    }

    public function canAccessAppPanel(AppPanel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel) {
            AppPanel::Admin => $this->isSuperAdmin() || $this->can('admin.access'),
            AppPanel::Ops => $this->isSuperAdmin() || $this->can('ops.access'),
        };
    }

    /**
     * Filament: only the admin panel exists in Filament (the Ops app is custom Livewire).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->canAccessAppPanel(AppPanel::Admin);
    }

    /**
     * Branch IDs this user may act on for an ability (null = all branches).
     *
     * @return list<int>|null
     */
    public function branchIdsFor(string $permission): ?array
    {
        return app(BranchScopeResolver::class)->branchIdsFor($this, $permission);
    }

    public function canInBranch(string $permission, Branch|int|null $branch): bool
    {
        return app(BranchScopeResolver::class)->allows($this, $permission, $branch);
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email ?? $this->username;
    }

    public function preferredLocale(): string
    {
        return $this->locale;
    }
}
