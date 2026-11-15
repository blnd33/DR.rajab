<?php

namespace App\Services\Users;

use App\Enums\Role;
use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryStatus;
use App\Models\Branch;
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
     * @param  array{name: string, phone?: ?string, branches?: list<int|string>}  $data
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
            'primary_branch_id' => $this->branches($data)[0] ?? null,
            'is_active' => true,
            'roles' => [Role::Technician->value],
            'permissions' => [],
            'branches' => $this->branches($data),
        ], $by);
    }

    /**
     * @param  array{name: string, phone?: ?string, is_active?: bool, branches?: list<int|string>}  $data
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
            ...(array_key_exists('branches', $data) ? ['branches' => $this->branches($data), 'primary_branch_id' => $this->branches($data)[0] ?? null] : []),
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

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function branches(array $data): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($data['branches'] ?? []))));

        return Branch::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
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
