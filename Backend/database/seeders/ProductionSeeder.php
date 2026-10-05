<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\GlobalSetting;
use Illuminate\Database\Seeder;

/**
 * The ONLY seeder that may touch a production database.
 *
 * It creates the bare minimum an empty install needs to be usable — a super
 * admin, a first branch, its admin, and the singleton settings row — and nothing
 * else. No demo users, no fake money. Everything comes from .env, and every
 * write is a firstOrCreate keyed on a natural identifier, so re-running it is
 * a no-op rather than a duplicate.
 *
 *   php artisan db:seed --class=ProductionSeeder
 *
 * Demo data lives in DatabaseSeeder, which is for local and QA only.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $superPhone = (string) config('auth.super_admin.phone');
        $superPassword = (string) config('auth.super_admin.password');

        if ($superPhone === '' || $superPassword === '') {
            $this->command?->error('SUPER_ADMIN_PHONE and SUPER_ADMIN_PASSWORD must be set in .env.');

            return;
        }

        // firstOrCreate, not updateOrCreate: a re-run must never put the .env
        // bootstrap passwords back over the ones chosen at first login.
        $super = Admin::firstOrCreate(
            ['phone' => $superPhone],
            [
                'name' => (string) config('auth.super_admin.name', 'Super Admin'),
                'email' => config('auth.super_admin.email') ?: null,
                'domain' => config('auth.super_admin.domain') ?: null,
                'password' => $superPassword,
                'role' => 'super_admin',
                'is_active' => true,
                // Whoever installs this must set their own password on first
                // login — the .env value is a bootstrap secret, not a credential.
                'must_change_password' => true,
            ],
        );

        // The config keys always exist (null when the env is unset), so a
        // config() default would never apply: fall back explicitly.
        $branch = Branch::firstOrCreate(
            ['code' => (string) (config('auth.default_branch.code') ?: 'MAIN')],
            [
                'name' => (string) (config('auth.default_branch.name') ?: 'Main Branch'),
                'domain' => config('auth.default_branch.domain') ?: null,
                'is_active' => true,
                'created_by' => $super->id,
            ],
        );

        $adminPhone = (string) config('auth.default_admin.phone');
        $adminPassword = (string) config('auth.default_admin.password');

        // Never the super admin's phone: that would demote the super admin.
        if ($adminPhone !== '' && $adminPassword !== '' && $adminPhone !== $superPhone) {
            Admin::firstOrCreate(
                ['phone' => $adminPhone],
                [
                    'name' => (string) config('auth.default_admin.name', 'Admin'),
                    'email' => config('auth.default_admin.email') ?: null,
                    'domain' => config('auth.default_admin.domain') ?: null,
                    'password' => $adminPassword,
                    'role' => (string) config('auth.default_admin.role', 'admin'),
                    'is_active' => true,
                    'must_change_password' => true,
                    'branch_id' => $branch->id,
                ],
            );
        }

        // Singleton row. Defaults are all "off" — no masking, no maintenance.
        if (GlobalSetting::query()->count() === 0) {
            GlobalSetting::create(['created_by' => $super->id]);
        }

        $this->command?->info('Production seed complete. Change both bootstrap passwords now.');
    }
}
