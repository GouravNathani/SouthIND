<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The scheduled cleanup jobs. Each one deletes data, so each is pinned down to
 * delete exactly what it should and nothing else.
 */
class HousekeepingCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Point storage at a throwaway directory so the prune commands can never
        // touch the real cache/log files of this checkout.
        $this->storage = sys_get_temp_dir() . '/sind-housekeeping-' . Str::random(8);
        File::ensureDirectoryExists($this->storage . '/framework/cache/data/ab/cd');
        File::ensureDirectoryExists($this->storage . '/app/private/logs');
        File::ensureDirectoryExists($this->storage . '/app/public/deposit-receipts');
        File::ensureDirectoryExists($this->storage . '/logs');

        $this->app->useStoragePath($this->storage);
        config([
            'filesystems.disks.local.root' => $this->storage . '/app/private',
            'filesystems.disks.public.root' => $this->storage . '/app/public',
        ]);
        Storage::forgetDisk(['local', 'public']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    private function user(): User
    {
        $branch = Branch::create(['name' => 'Main', 'code' => 'MAIN']);

        return User::create([
            'name' => 'Player',
            'phone' => '9000011111',
            'unique_number' => '111111',
            'password' => bcrypt('secret'),
            'branch_id' => $branch->id,
            'status' => User::STATUS_ACTIVE,
        ]);
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Super',
            'email' => 'super@test.local',
            'phone' => '9800000001',
            'password' => 'secret',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_purge_all_removes_every_login_and_reset_token(): void
    {
        $this->user()->createToken('user');
        $this->admin()->createToken('admin');
        DB::table('password_reset_tokens')->insert([
            'email' => 'someone@test.local',
            'token' => 'hash',
            'created_at' => now(),
        ]);

        $this->artisan('tokens:purge-all')->assertSuccessful();

        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_cleanup_removes_only_expired_tokens(): void
    {
        $user = $this->user();
        $user->createToken('expired', ['*'], now()->subMinute());
        $user->createToken('valid', ['*'], now()->addHour());
        $user->createToken('no-expiry');

        $this->artisan('tokens:cleanup')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['valid', 'no-expiry'], PersonalAccessToken::pluck('name')->all());
    }

    public function test_nightly_purge_is_scheduled_at_midnight(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'tokens:purge-all'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
    }

    public function test_cache_prune_removes_only_expired_entries(): void
    {
        $dir = $this->storage . '/framework/cache/data/ab/cd';
        file_put_contents("{$dir}/expired", (time() - 10) . serialize('old'));
        file_put_contents("{$dir}/live", (time() + 3600) . serialize('fresh'));
        file_put_contents("{$dir}/forever", '9999999999' . serialize('kept'));
        file_put_contents("{$dir}/foreign", 'not a cache payload');

        $this->artisan('cache:prune-files')->assertSuccessful();

        $this->assertFileDoesNotExist("{$dir}/expired");
        $this->assertFileExists("{$dir}/live");
        $this->assertFileExists("{$dir}/forever");
        $this->assertFileExists("{$dir}/foreign");
    }

    public function test_cache_prune_drops_hash_directories_it_empties(): void
    {
        $dir = $this->storage . '/framework/cache/data/ab/cd';
        file_put_contents("{$dir}/expired", (time() - 10) . serialize('old'));

        $this->artisan('cache:prune-files')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($this->storage . '/framework/cache/data/ab');
        $this->assertDirectoryExists($this->storage . '/framework/cache/data');
    }

    public function test_log_prune_keeps_seven_days_of_every_log(): void
    {
        $gone = [
            'logs/laravel-2026-01-01.log' => 10,
            'logs/laravel.log' => 60,               // legacy single file
            'app/private/logs/api-2026-01-01.log' => 10,
            'app/private/logs/api.log' => 30,       // legacy single file
        ];
        $kept = [
            'logs/laravel-today.log' => 0,
            'app/private/logs/api-recent.log' => 2,
            'logs/notes.txt' => 30,                 // not a log file
        ];

        foreach ($gone + $kept as $path => $daysAgo) {
            file_put_contents("{$this->storage}/{$path}", '{}');
            touch("{$this->storage}/{$path}", now()->subDays($daysAgo)->getTimestamp());
        }

        $this->artisan('logs:prune')->assertSuccessful();

        foreach (array_keys($gone) as $path) {
            $this->assertFileDoesNotExist("{$this->storage}/{$path}");
        }
        foreach (array_keys($kept) as $path) {
            $this->assertFileExists("{$this->storage}/{$path}");
        }
    }

    private function receipt(string $name, int $daysAgo): string
    {
        $file = "{$this->storage}/app/public/deposit-receipts/{$name}";
        file_put_contents($file, 'image');
        touch($file, now()->subDays($daysAgo)->getTimestamp());

        return "/storage/deposit-receipts/{$name}"; // what Storage::url() stores
    }

    private function deposit(string $status, int $daysAgo, ?string $receipt): Deposit
    {
        static $n = 0;
        $n++;

        $branch = Branch::query()->firstOrCreate(['code' => 'MAIN'], ['name' => 'Main']);
        $user = User::create([
            'name' => 'Player',
            'phone' => '90001' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'unique_number' => (string) (200000 + $n),
            'password' => bcrypt('secret'),
            'branch_id' => $branch->id,
            'status' => User::STATUS_ACTIVE,
        ]);
        $account = Account::create(['name' => 'UPI', 'type' => 'upi', 'upi_id' => 'a@upi', 'branch_id' => $branch->id]);

        $deposit = Deposit::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'branch_id' => $branch->id,
            'play_id' => $user->unique_number,
            'amount' => 500,
            'status' => $status,
            'receipt_image_path' => $receipt,
        ]);
        $created = now()->subDays($daysAgo);
        $deposit->forceFill(['created_at' => $created, 'updated_at' => $created])->save();

        return $deposit;
    }

    public function test_receipt_cleanup_keeps_seven_days_and_anything_still_open(): void
    {
        $dir = "{$this->storage}/app/public/deposit-receipts";
        $old = $this->deposit(Deposit::STATUS_APPROVED, 10, $this->receipt('old.jpg', 10));
        $recent = $this->deposit(Deposit::STATUS_APPROVED, 2, $this->receipt('recent.jpg', 2));
        $open = $this->deposit(Deposit::STATUS_PENDING, 10, $this->receipt('open.jpg', 10));
        $this->receipt('orphan-old.jpg', 10);
        $this->receipt('orphan-new.jpg', 1);
        file_put_contents("{$this->storage}/app/public/banner.jpg", 'image');
        touch("{$this->storage}/app/public/banner.jpg", now()->subDays(30)->getTimestamp());
        $oldUpdatedAt = $old->updated_at->toDateTimeString();

        $this->artisan('deposits:cleanup-receipts --days=7')->assertSuccessful();

        $this->assertFileDoesNotExist("{$dir}/old.jpg");
        $this->assertFileDoesNotExist("{$dir}/orphan-old.jpg");
        $this->assertFileExists("{$dir}/recent.jpg");
        $this->assertFileExists("{$dir}/open.jpg");       // still pending: an admin needs it
        $this->assertFileExists("{$dir}/orphan-new.jpg");
        $this->assertFileExists("{$this->storage}/app/public/banner.jpg"); // outside deposit-receipts/

        $this->assertNull($old->fresh()->receipt_image_path);
        $this->assertSame($oldUpdatedAt, $old->fresh()->updated_at->toDateTimeString());
        $this->assertNotNull($recent->fresh()->receipt_image_path);
        $this->assertNotNull($open->fresh()->receipt_image_path);
    }

    public function test_receipt_cleanup_runs_every_night(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'deposits:cleanup-receipts'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('--days=7', (string) $event->command);
        $this->assertSame('0 1 * * *', $event->expression);
    }
}
