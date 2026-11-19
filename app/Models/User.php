<?php

namespace App\Models;

use App\Enums\Panel as AppPanel;
use App\Enums\Role;
use App\Enums\SurgeryDoctorRole;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
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
    use HasRoles {
        hasPermissionTo as private roleHasPermissionTo;
    }
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

    /**
     * Surgeries this user worked on as a technician.
     *
     * @return BelongsToMany<Surgery, $this>
     */
    public function technicianSurgeries(): BelongsToMany
    {
        return $this->belongsToMany(Surgery::class, 'surgery_doctors')->wherePivot('role', SurgeryDoctorRole::Technician->value)->withPivot('role');
    }

    /**
     * Company-agent profile (CV, position, salary): technicians, and employees linked to their account.
     *
     * @return HasOne<CompanyAgent, $this>
     */
    public function agentProfile(): HasOne
    {
        return $this->hasOne(CompanyAgent::class);
    }

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
     * Technicians are names picked on surgeries: no login, no permissions.
     */
    public function isTechnician(): bool
    {
        return $this->hasRole(Role::Technician->value);
    }

    /**
     * Spatie's permission check, except that a technician never has a permission, whatever is
     * attached to the account.
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        if ($this->isTechnician()) {
            return false;
        }

        return $this->roleHasPermissionTo($permission, $guardName);
    }

    /**
     * Active technicians (for the surgery technician picker), of one branch when given.
     *
     * @return array<int, string>
     */
    public static function technicianOptions(?int $branchId = null): array
    {
        $branchId ??= self::focusedBranchId();

        return static::query()
            ->where('is_active', true)
            ->role(Role::Technician->value)
            ->when($branchId !== null, fn (Builder $q) => $q->worksInBranch($branchId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * People who work in a branch: members of it, those whose main branch it is, and
     * "all branches" accounts.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWorksInBranch(Builder $query, int $branchId): void
    {
        $query->where(fn (Builder $q) => $q->where('all_branches', true)
            ->orWhere('primary_branch_id', $branchId)
            ->orWhereHas('branches', fn (Builder $b) => $b->where('branches.id', $branchId)));
    }

    /**
     * The branch chosen in the admin top bar, for the signed-in user (null on the phone or when
     * all branches are shown).
     */
    public static function focusedBranchId(): ?int
    {
        $user = Auth::user();

        return $user instanceof self ? app(BranchScopeResolver::class)->focusedBranch($user) : null;
    }

    /**
     * Active users who may log surgeries (for doctor pickers), of one branch when given
     * (default: the branch chosen in the admin top bar).
     *
     * @return array<int, string>
     */
    public static function doctorOptions(?int $branchId = null): array
    {
        $branchId ??= self::focusedBranchId();

        return static::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->permission('surgeries.create')->orWhereHas('roles', fn ($r) => $r->where('name', Role::SuperAdmin->value)))
            ->when($branchId !== null, fn (Builder $q) => $q->worksInBranch($branchId))
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
        if (! $this->is_active || $this->isTechnician()) {
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
