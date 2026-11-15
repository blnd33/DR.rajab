<?php

namespace App\Services\Users;

use App\Enums\Role;
use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryStatus;
use App\Models\Surgery;
use App\Models\User;
use App\Support\Locale\Display;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Technicians who work with us on surgeries. Each has an account so their work can be counted,
 * but the account never signs in and never holds permissions (a random password nobody knows,
 * the empty technician role, and Gate::before refuses every ability).
 */
class TechnicianService
{
    public function __construct(private readonly UserService $users) {}

    /**
     * @param  array{name: string, phone?: ?string}  $data
     */
    public function create(array $data, User $by): User
    {
        $this->authorize($by);

        return $this->users->create([
            'name' => trim($data['name']),
            'username' => $this->username($data['name']),
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'password' => Str::password(48),
            'locale' => config('locales.default', 'en'),
            'all_branches' => false,
            'is_active' => true,
            'roles' => [Role::Technician->value],
            'permissions' => [],
            'branches' => [],
        ], $by);
    }

    /**
     * @param  array{name: string, phone?: ?string, is_active?: bool}  $data
     */
    public function update(User $technician, array $data, User $by): User
    {
        $this->authorize($by);
        abort_unless($technician->isTechnician(), 404);

        return $this->users->update($technician, [
            'name' => trim($data['name']),
            'phone' => filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null,
            'is_active' => (bool) ($data['is_active'] ?? $technician->is_active),
            'roles' => [Role::Technician->value],
            'permissions' => [],
        ], $by);
    }

    /**
     * @return Builder<User>
     */
    public function query(): Builder
    {
        return User::query()->role(Role::Technician->value);
    }

    /**
     * Completed surgeries a technician worked on.
     *
     * @return Builder<Surgery>
     */
    public function surgeries(User $technician): Builder
    {
        return Surgery::query()
            ->where('status', SurgeryStatus::Completed)
            ->whereHas('doctors', fn ($d) => $d->where('users.id', $technician->id)->where('surgery_doctors.role', SurgeryDoctorRole::Technician->value));
    }

    /**
     * Fees of the completed surgeries a technician worked on, one line per currency.
     *
     * @return list<string>
     */
    public function feeTotals(User $technician): array
    {
        return $this->surgeries($technician)->whereNotNull('fee_amount')
            ->groupBy('fee_currency')->selectRaw('fee_currency, SUM(fee_amount) AS total')->orderBy('fee_currency')
            ->toBase()->get()
            ->map(fn ($row) => Display::money((string) $row->total, (string) $row->fee_currency))
            ->all();
    }

    private function username(string $name): string
    {
        $base = 'tech.'.(Str::slug(Str::ascii($name), '.') ?: 'technician');
        $base = mb_substr($base, 0, 50);

        do {
            $candidate = $base.'.'.Str::lower(Str::random(5));
        } while (User::query()->whereRaw('lower(username) = ?', [$candidate])->exists());

        return $candidate;
    }

    private function authorize(User $by): void
    {
        if (! $by->can('technicians.manage')) {
            throw new AuthorizationException(__('You are not allowed to manage technicians.'));
        }
    }
}
