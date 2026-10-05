<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Branch;
use App\Models\GlobalSetting;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auth.super_admin.phone' => '9000000001',
            'auth.super_admin.password' => 'bootstrap-super',
            'auth.default_admin.phone' => '9000000002',
            'auth.default_admin.password' => 'bootstrap-admin',
            'auth.default_branch.code' => null,
            'auth.default_branch.name' => null,
            'auth.default_branch.domain' => null,
        ]);
    }

    public function test_an_unset_branch_code_falls_back_to_main(): void
    {
        $this->seed(ProductionSeeder::class);

        $branch = Branch::sole();
        $this->assertSame('MAIN', $branch->code);
        $this->assertSame('Main Branch', $branch->name);
        $this->assertSame($branch->id, Admin::where('phone', '9000000002')->value('branch_id'));
        $this->assertSame(1, GlobalSetting::count());
    }

    public function test_a_re_run_keeps_the_passwords_chosen_after_first_login(): void
    {
        $this->seed(ProductionSeeder::class);

        foreach (['9000000001' => 'my-own-super', '9000000002' => 'my-own-admin'] as $phone => $password) {
            Admin::where('phone', $phone)->first()
                ->forceFill(['password' => $password, 'must_change_password' => false])->save();
        }

        $this->seed(ProductionSeeder::class);

        $super = Admin::where('phone', '9000000001')->first();
        $admin = Admin::where('phone', '9000000002')->first();
        $this->assertTrue(Hash::check('my-own-super', $super->password));
        $this->assertTrue(Hash::check('my-own-admin', $admin->password));
        $this->assertFalse((bool) $super->must_change_password);
        $this->assertSame('super_admin', $super->role);
        $this->assertSame(2, Admin::count());
        $this->assertSame(1, Branch::count());
    }

    public function test_the_default_admin_never_overwrites_the_super_admin(): void
    {
        config(['auth.default_admin.phone' => '9000000001']);

        $this->seed(ProductionSeeder::class);

        $this->assertSame(1, Admin::count());
        $this->assertSame('super_admin', Admin::sole()->role);
    }
}
