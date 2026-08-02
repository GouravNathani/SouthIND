<?php

namespace Tests;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but an in-memory database.
     *
     * phpunit.xml sets DB_DATABASE=:memory:, but a CACHED CONFIG silently wins
     * over it — and RefreshDatabase then happily migrate:fresh's whatever the
     * cached config points at. On a sibling project that wiped a real seeded
     * development database, and the failure is invisible, because the tests
     * still pass.
     *
     * If this ever throws: `php artisan config:clear`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) DB::connection()->getDatabaseName();

        if (! in_array($database, [':memory:', ''], true)) {
            throw new RuntimeException(
                "Tests are pointed at '{$database}', not an in-memory database. "
                .'A cached config is overriding phpunit.xml — run `php artisan config:clear`. '
                .'Refusing to run so the tests cannot wipe a real database.'
            );
        }
    }

    /**
     * A branch with a super admin, a branch admin, a user and a deposit
     * account — the minimum needed to exercise any money path.
     *
     * @param  array<string, mixed>  $accountOverrides
     * @return array{branch: Branch, super: Admin, admin: Admin, user: User, account: Account}
     */
    protected function makeBranch(string $code = 'CHN', array $accountOverrides = []): array
    {
        $super = Admin::create([
            'name' => 'Super '.$code,
            'phone' => '+9199'.substr(md5($code.'super'), 0, 8),
            'password' => 'secret123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $branch = Branch::create([
            'name' => $code.' Branch',
            'code' => $code,
            'is_active' => true,
            'created_by' => $super->id,
        ]);

        $admin = Admin::create([
            'name' => $code.' Admin',
            'phone' => '+9188'.substr(md5($code), 0, 8),
            'password' => 'secret123',
            'role' => 'admin',
            'is_active' => true,
            'branch_id' => $branch->id,
        ]);

        $user = User::create([
            'name' => $code.' User',
            'phone' => '+9190'.substr(md5($code), 0, 8),
            'password' => 'secret123',
            'mpin' => '123456',
            'play_id' => $code.'PLAY1',
            'status' => User::STATUS_ACTIVE,
            'branch_id' => $branch->id,
        ]);

        $account = Account::create(array_merge([
            'name' => $code.' UPI',
            'type' => 'upi',
            'used_for' => 'deposit',
            'upi_id' => strtolower($code).'@okaxis',
            'status' => 'active',
            'branch_id' => $branch->id,
            'created_by' => $admin->id,
            'owner_admin_id' => $admin->id,
        ], $accountOverrides));

        return compact('branch', 'super', 'admin', 'user', 'account');
    }
}
