<?php

namespace App\Services\Companies;

use App\Enums\Role;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Support\Str;

/**
 * Al-Fadhaa' Bureau has no login of its own: KurdishMedicus' super admin opens it with the button.
 * In the company this process works in, this keeps a copy of that person's account (same username,
 * name, email, language; Super Admin; all stations; a random password nobody knows), so what they
 * do there is recorded under their name.
 */
class CompanyAccountService
{
    public function __construct(private readonly UserService $users) {}

    /**
     * @param  array{username: string, name: string, email: ?string, locale: string}  $who
     */
    public function accountFor(array $who): User
    {
        $user = User::query()->where('username', $who['username'])->first();
        $data = [
            'name' => $who['name'],
            'email' => $who['email'] ?: null,
            'locale' => in_array($who['locale'], ['en', 'ar', 'ckb'], true) ? $who['locale'] : 'en',
            'all_branches' => true,
            'primary_branch_id' => null,
            'is_active' => true,
            'roles' => [Role::SuperAdmin->value],
            'branches' => [],
        ];

        if ($user === null) {
            return $this->users->create($data + ['username' => $who['username'], 'password' => Str::password(40)]);
        }

        return $this->users->update($user, $data);
    }
}
