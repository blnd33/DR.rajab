<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * One demo user per role, in different languages (local/staging only).
 * Password: DEMO_USER_PASSWORD from .env (see .env.example).
 *
 *   superadmin  Super Admin   en  all branches
 *   accountant  Accountant    ar  all branches
 *   doctor      Doctor        ckb DHK + EBL
 *   cashier     Cashier       ar  EBL
 *   staff       Branch Staff  ckb DHK
 *   staff.bgw   Branch Staff  en  BGW
 */
class DemoUserSeeder extends Seeder
{
    public function run(UserService $users): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Demo users are never created in production.');
        }

        $password = (string) config('orthotrack.demo_user_password');
        $branch = fn (string $code) => Branch::query()->where('code', $code)->value('id');

        $demo = [
            ['username' => 'superadmin', 'name' => 'Dr. Demo Surgeon', 'role' => Role::SuperAdmin, 'locale' => 'en', 'all' => true, 'branches' => [], 'primary' => 'DHK'],
            ['username' => 'accountant', 'name' => 'Demo Accountant', 'role' => Role::Accountant, 'locale' => 'ar', 'all' => true, 'branches' => [], 'primary' => 'DHK'],
            ['username' => 'doctor', 'name' => 'Dr. Demo Doctor', 'role' => Role::Doctor, 'locale' => 'ckb', 'all' => false, 'branches' => ['DHK', 'EBL'], 'primary' => 'DHK'],
            ['username' => 'cashier', 'name' => 'Demo Cashier', 'role' => Role::Cashier, 'locale' => 'ar', 'all' => false, 'branches' => ['EBL'], 'primary' => 'EBL'],
            ['username' => 'staff', 'name' => 'Demo Staff Duhok', 'role' => Role::BranchStaff, 'locale' => 'ckb', 'all' => false, 'branches' => ['DHK'], 'primary' => 'DHK'],
            ['username' => 'staff.bgw', 'name' => 'Demo Staff Baghdad', 'role' => Role::BranchStaff, 'locale' => 'en', 'all' => false, 'branches' => ['BGW'], 'primary' => 'BGW'],
        ];

        foreach ($demo as $row) {
            if (User::query()->where('username', $row['username'])->exists()) {
                continue;
            }

            $users->create([
                'name' => $row['name'],
                'username' => $row['username'],
                'email' => "{$row['username']}@demo.orthotrack.test",
                'password' => $password,
                'locale' => $row['locale'],
                'all_branches' => $row['all'],
                'primary_branch_id' => $branch($row['primary']),
                'is_active' => true,
                'roles' => [$row['role']->value],
                'branches' => array_values(array_filter(array_map($branch, $row['branches']))),
            ]);
        }
    }
}
