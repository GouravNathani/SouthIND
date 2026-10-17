<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * adda:import wipes SouthIND and refills it from an Adda-shaped database with
 * the same ids. The source here is SouthIND's own schema bent into Adda's
 * shape (Adda-only columns/tables added, SouthIND-only tables removed).
 */
class AddaImportTest extends TestCase
{
    use RefreshDatabase;

    private const SRC = 'adda_test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'payout.start_at' => '2026-10-10 00:00:00',
            'wallet.wallet_id' => '9012341234',
            'database.connections.' . self::SRC => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        $this->artisan('migrate', ['--database' => self::SRC, '--force' => true]);
        $this->bendIntoAddaShape();
        $this->seedSouthInd();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/adda-import'));
        parent::tearDown();
    }

    private function src(): \Illuminate\Database\Connection
    {
        return DB::connection(self::SRC);
    }

    private function bendIntoAddaShape(): void
    {
        $schema = Schema::connection(self::SRC);
        $schema->disableForeignKeyConstraints();

        foreach (['commission_payouts', 'commission_entries', 'commission_accounts', 'referral_audits',
            'winner_streak_entries', 'winner_streak_cycles', 'winner_streak_settings',
            'legacy_adda_rows', 'sso_tickets',
            // SouthIND-only tables (clubs, home strip) that Adda does not have.
            'club_user', 'clubs', 'branch_tickers'] as $table) {
            $schema->dropIfExists($table);
        }

        $schema->table('users', fn (Blueprint $t) => $t->renameColumn('referred_by', 'referred_by_user_id'));
        $schema->table('accounts', fn (Blueprint $t) => $t->decimal('opening_balance', 12, 2)->default(0));
        $schema->table('admins', function (Blueprint $t) {
            $t->text('two_factor_secret')->nullable();
            $t->boolean('two_factor_required')->default(false);
            $t->string('withdraw_account_mode', 16)->default('off');
        });
        $schema->table('banners', fn (Blueprint $t) => $t->boolean('is_flash')->default(false));
        $schema->table('withdrawals', fn (Blueprint $t) => $t->unsignedBigInteger('account_id')->nullable());

        $schema->create('admin_login_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('status');
            $t->timestamps();
        });
        $schema->create('user_streaks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedInteger('current_day')->default(0);
            $t->decimal('coins_balance', 12, 2)->default(0);
            $t->timestamps();
        });
        $schema->create('refresh_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('token_hash');
        });

        $this->src()->statement('CREATE TABLE restore_support_conversations AS SELECT * FROM support_conversations WHERE 0');
        $this->src()->statement('CREATE TABLE restore_support_messages AS SELECT * FROM support_messages WHERE 0');

        $schema->enableForeignKeyConstraints();
    }

    /** What SouthIND holds before the move. */
    private function seedSouthInd(): void
    {
        DB::table('admins')->insert(['id' => 1, 'name' => 'Super', 'phone' => '9012341234', 'password' => bcrypt('x'), 'role' => 'super_admin', 'is_active' => 1]);
        DB::table('branches')->insert(['id' => 1, 'name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_by' => 1]);
        DB::table('users')->insert(['id' => 1, 'name' => 'Old', 'phone' => '9999999999', 'password' => 'x', 'branch_id' => 1, 'status' => 'active']);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 1, 'name' => 'user', 'token' => str_repeat('a', 64)]);
        DB::table('wallet_charges')->insert(['reference' => 'sind-support-in-1', 'channel' => 'support', 'direction' => 'in', 'cost_field' => 'support_in_cost', 'cost_value' => 0.1, 'coins' => 0.1, 'status' => 'unsettled', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A small but complete Adda. */
    private function seedAdda(): void
    {
        $db = $this->src();
        // Adda has far fewer foreign keys than SouthIND; seed it the way it is.
        $db->statement('PRAGMA foreign_keys = OFF');
        $db->table('admins')->insert([
            ['id' => 10, 'name' => 'Adda Super', 'phone' => '7867867860', 'password' => bcrypt('p'), 'role' => 'super_admin', 'is_active' => 1, 'branch_id' => null, 'parent_id' => null, 'withdraw_account_mode' => 'off', 'two_factor_secret' => 'enc-secret'],
            ['id' => 11, 'name' => 'Adda Admin', 'phone' => '7000000011', 'password' => bcrypt('p'), 'role' => 'admin', 'is_active' => 1, 'branch_id' => 2, 'parent_id' => null, 'withdraw_account_mode' => 'optional', 'two_factor_secret' => null],
            ['id' => 12, 'name' => 'Adda Staff', 'phone' => '7000000012', 'password' => bcrypt('p'), 'role' => 'staff', 'is_active' => 1, 'branch_id' => 2, 'parent_id' => 11, 'withdraw_account_mode' => 'off', 'two_factor_secret' => null],
        ]);
        $db->table('branches')->insert([
            ['id' => 1, 'name' => 'Main WD', 'code' => 'MAINWD', 'domain' => 'bettingadda9.com', 'is_active' => 1, 'created_by' => 10],
            ['id' => 2, 'name' => 'Branch 001', 'code' => '001', 'domain' => null, 'is_active' => 1, 'created_by' => 10],
        ]);
        $db->table('global_settings')->insert(['id' => 1, 'whatsapp_link' => 'https://wa.me/1', 'user_panel_maintenance_enabled' => 0, 'created_by' => 10]);
        $db->table('app_settings')->insert(['id' => 1, 'owner_admin_id' => 11, 'branch_id' => 2, 'deposit_wa' => '911']);
        $db->table('users')->insert([
            ['id' => 100, 'name' => 'Ravi', 'phone' => '9000000100', 'password' => bcrypt('p'), 'mpin' => '111111', 'play_id' => 'P100', 'branch_id' => 2, 'status' => 'active', 'referral_code' => 'AAAA1111', 'referred_by_user_id' => null, 'unique_number' => '001-000001'],
            ['id' => 101, 'name' => 'Sita', 'phone' => '9000000101', 'password' => bcrypt('p'), 'mpin' => '222222', 'play_id' => 'P101', 'branch_id' => 2, 'status' => 'banned', 'referral_code' => 'BBBB2222', 'referred_by_user_id' => 100, 'unique_number' => '001-000002'],
            ['id' => 102, 'name' => 'Main', 'phone' => '9000000102', 'password' => bcrypt('p'), 'mpin' => '333333', 'play_id' => null, 'branch_id' => 1, 'status' => 'active', 'referral_code' => null, 'referred_by_user_id' => null, 'unique_number' => 'MAINWD-000001'],
        ]);
        $db->table('accounts')->insert(['id' => 50, 'name' => 'UPI', 'type' => 'upi', 'upi_id' => 'a@upi', 'status' => 'active', 'branch_id' => 2, 'created_by' => 11, 'owner_admin_id' => 11, 'opening_balance' => 500, 'logo_path' => '/storage/scanner-images/q.png']);
        $db->table('deposits')->insert([
            ['id' => 1000, 'user_id' => 100, 'account_id' => 50, 'branch_id' => 2, 'play_id' => 'P100', 'amount' => 1000, 'status' => 'approved', 'approved_by' => 11, 'approved_at' => '2026-08-01 10:00:00', 'receipt_image_path' => 'https://wd.bettingadda9.com/storage/deposit-receipts/r.jpg', 'created_at' => '2026-08-01 09:00:00'],
            ['id' => 1001, 'user_id' => 101, 'account_id' => 50, 'branch_id' => 2, 'play_id' => 'P101', 'amount' => 250.5, 'status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'receipt_image_path' => '/storage/deposit-receipts/s.jpg', 'created_at' => '2026-10-07 09:00:00'],
            ['id' => 1002, 'user_id' => 100, 'account_id' => 50, 'branch_id' => 2, 'play_id' => 'P100', 'amount' => 300, 'status' => 'approved', 'approved_by' => 99, 'approved_at' => '2026-09-01 10:00:00', 'receipt_image_path' => null, 'created_at' => '2026-09-01 09:00:00'],
        ]);
        $db->table('withdrawals')->insert(['id' => 2000, 'user_id' => 100, 'branch_id' => 2, 'play_id' => 'P100', 'amount' => 700, 'status' => 'processing', 'destination_type' => 'upi', 'upi_id' => 'r@upi', 'account_id' => null]);
        $db->table('banners')->insert([
            ['id' => 5, 'image_path' => 'storage/banners/b.png', 'is_active' => 1, 'is_flash' => 0, 'branch_id' => 2],
            ['id' => 6, 'image_path' => 'storage/banners/f.png', 'is_active' => 1, 'is_flash' => 1, 'branch_id' => 2],
        ]);
        $db->table('support_conversations')->insert(['id' => 300, 'user_id' => 100, 'branch_id' => 2, 'status' => 'open']);
        $db->table('support_messages')->insert(['id' => 3000, 'conversation_id' => 300, 'sender_type' => 'user', 'sender_id' => 100, 'body' => 'hi', 'audio_path' => '/storage/chat-voice/v.webm']);

        // The 2026-09-24 restore staging: an old chat of Ravi's (he has a newer
        // one) and a whole chat of Sita's that is gone from live.
        $db->table('restore_support_conversations')->insert([
            ['id' => 290, 'user_id' => 100, 'branch_id' => 2, 'status' => 'open', 'flagged' => 0, 'awaiting_reply' => 0, 'user_unread_count' => 0, 'admin_unread_count' => 0],
            ['id' => 291, 'user_id' => 101, 'branch_id' => 2, 'status' => 'open', 'flagged' => 0, 'awaiting_reply' => 0, 'user_unread_count' => 0, 'admin_unread_count' => 0],
            ['id' => 300, 'user_id' => 100, 'branch_id' => 2, 'status' => 'open', 'flagged' => 0, 'awaiting_reply' => 0, 'user_unread_count' => 0, 'admin_unread_count' => 0],
        ]);
        $db->table('restore_support_messages')->insert([
            ['id' => 2900, 'conversation_id' => 290, 'sender_type' => 'user', 'sender_id' => 100, 'body' => 'old ravi'],
            ['id' => 2910, 'conversation_id' => 291, 'sender_type' => 'user', 'sender_id' => 101, 'body' => 'old sita'],
            ['id' => 3000, 'conversation_id' => 300, 'sender_type' => 'user', 'sender_id' => 100, 'body' => 'hi'],
        ]);

        $db->table('admin_login_events')->insert(['id' => 1, 'admin_id' => 10, 'status' => 'success']);
        $db->table('user_streaks')->insert(['id' => 1, 'user_id' => 100, 'current_day' => 4, 'coins_balance' => 40]);
        $db->table('wallet_charges')->insert(['reference' => 'adda-support-in-9', 'channel' => 'support', 'direction' => 'in', 'cost_field' => 'support_in_cost', 'cost_value' => 0.1, 'coins' => 0.1, 'status' => 'unsettled', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('personal_access_tokens')->insert(['tokenable_type' => 'App\\Models\\User', 'tokenable_id' => 100, 'name' => 'user', 'token' => str_repeat('b', 64)]);
        $db->table('refresh_tokens')->insert(['id' => 1, 'token_hash' => 'h']);
    }

    private function import(array $options = [])
    {
        return $this->artisan('adda:import', array_merge(['--adda-connection' => self::SRC], $options));
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $this->seedAdda();

        $this->import()
            ->expectsOutputToContain('1 user(s) hold 40 streak coins')
            ->expectsOutputToContain('1 admin(s) have the withdrawal account picker on')
            ->expectsOutputToContain('1 account(s) have an opening balance')
            ->expectsOutputToContain('Dry run OK')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame('MAIN', DB::table('branches')->value('code'));
        $this->assertSame(0, DB::table('legacy_adda_rows')->count());
    }

    public function test_execute_needs_the_confirm_phrase_and_the_billing_cutoff(): void
    {
        $this->seedAdda();

        $this->import(['--execute' => true])->assertFailed();

        config(['payout.start_at' => '']);
        $this->import(['--execute' => true, '--confirm' => 'WIPE-SOUTHIND'])
            ->expectsOutputToContain('PAYOUT_START_AT')
            ->assertFailed();

        config(['payout.start_at' => '2026-10-10 00:00:00', 'wallet.wallet_id' => '']);
        $this->import(['--execute' => true, '--confirm' => 'WIPE-SOUTHIND'])
            ->expectsOutputToContain('WALLET_ID')
            ->assertFailed();

        $this->assertSame('MAIN', DB::table('branches')->value('code'));
    }

    public function test_full_import_keeps_ids_links_and_history(): void
    {
        $this->seedAdda();

        $this->import(['--execute' => true, '--confirm' => 'WIPE-SOUTHIND'])
            ->expectsOutputToContain('Import complete')
            ->assertSuccessful();

        // Branches and admins: same ids, same names, circular links restored.
        $this->assertSame(['MAINWD', '001'], DB::table('branches')->orderBy('id')->pluck('code')->all());
        $this->assertSame(10, (int) DB::table('branches')->where('id', 2)->value('created_by'));
        $this->assertSame(11, (int) DB::table('admins')->where('id', 12)->value('parent_id'));

        // SouthIND's own super admin is back, after Adda's ids; old SouthIND data is gone.
        $sind = DB::table('admins')->where('phone', '9012341234')->first();
        $this->assertNotNull($sind);
        $this->assertGreaterThan(12, $sind->id);
        $this->assertNull(DB::table('branches')->where('code', 'MAIN')->first());
        $this->assertNull(DB::table('users')->where('phone', '9999999999')->first());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        // Users: same id, MPIN, play_id; referral codes parked; referrer linked.
        $ravi = DB::table('users')->find(100);
        $this->assertSame('111111', $ravi->mpin);
        $this->assertSame('P100', $ravi->play_id);
        $this->assertNull($ravi->referral_code);
        $this->assertSame(100, (int) DB::table('users')->where('id', 101)->value('referred_by'));
        $this->assertSame('banned', DB::table('users')->where('id', 101)->value('status'));

        // Money history: same rows and totals; Adda's "processing" lands in the queue.
        $this->assertSame(3, DB::table('deposits')->count());
        $this->assertEquals(1550.5, (float) DB::table('deposits')->sum('amount'));
        $this->assertSame('pending', DB::table('withdrawals')->where('id', 2000)->value('status'));
        $this->assertSame('storage/deposit-receipts/r.jpg', DB::table('deposits')->where('id', 1000)->value('receipt_image_path'));
        $this->assertSame('/storage/deposit-receipts/s.jpg', DB::table('deposits')->where('id', 1001)->value('receipt_image_path'));
        // The approving admin no longer exists in Adda: link cleared, row kept.
        $this->assertNull(DB::table('deposits')->where('id', 1002)->value('approved_by'));

        // Flash banner carried but hidden.
        $this->assertSame(0, (int) DB::table('banners')->where('id', 6)->value('is_active'));

        // Restored chats: Sita's whole chat back, Ravi's old messages join his live chat.
        $this->assertSame(300, (int) DB::table('support_messages')->where('id', 2900)->value('conversation_id'));
        $this->assertSame(291, (int) DB::table('support_messages')->where('id', 2910)->value('conversation_id'));
        $this->assertSame(2, DB::table('support_conversations')->count());
        $this->assertSame(3, DB::table('support_messages')->count());

        // Parked: Adda-only tables and column values, never the 2FA secret.
        $this->assertSame(1, DB::table('legacy_adda_rows')->where('source_table', 'user_streaks')->count());
        $this->assertSame(1, DB::table('legacy_adda_rows')->where('source_table', 'admin_login_events')->count());
        $this->assertSame(1, DB::table('legacy_adda_rows')->where('source_table', 'wallet_charges')->count());
        $parkedAccount = json_decode(DB::table('legacy_adda_rows')->where('source_table', 'accounts.dropped')->value('payload'), true);
        $this->assertEquals(['opening_balance' => 500], $parkedAccount);
        $parkedUsers = DB::table('legacy_adda_rows')->where('source_table', 'users.dropped')->pluck('payload')->implode(' ');
        $this->assertStringContainsString('AAAA1111', $parkedUsers);
        $parkedAdmins = DB::table('legacy_adda_rows')->where('source_table', 'admins.dropped')->pluck('payload')->implode(' ');
        $this->assertStringContainsString('optional', $parkedAdmins);
        $this->assertStringNotContainsString('enc-secret', DB::table('legacy_adda_rows')->pluck('payload')->implode(' '));

        // Billing: Adda's ledger is parked, SouthIND's own ledger survives the wipe.
        $this->assertSame(['sind-support-in-1'], DB::table('wallet_charges')->pluck('reference')->all());

        // Report file is written.
        $this->assertNotEmpty(File::glob(storage_path('app/adda-import/report-*.json')));
    }

    public function test_media_files_are_copied_add_only(): void
    {
        $this->seedAdda();
        $adda = storage_path('framework/testing/adda-media');
        File::deleteDirectory($adda);
        $put = function (string $root, string $file, string $body, ?int $mtime = null): void {
            File::ensureDirectoryExists(dirname("{$root}/{$file}"));
            File::put("{$root}/{$file}", $body);
            if ($mtime !== null) {
                touch("{$root}/{$file}", $mtime);
            }
        };
        // Everything Adda's rows point at, except s.jpg (Adda already lost it).
        $put($adda, 'deposit-receipts/r.jpg', 'receipt r', 1_790_000_000);
        $put($adda, 'scanner-images/q.png', 'qr');
        $put($adda, 'banners/b.png', 'adda banner');
        $put($adda, 'banners/f.png', 'flash');
        $put($adda, 'chat-voice/v.webm', 'voice');
        $put($adda, 'banners/old-unused.png', 'old');
        $put($adda, '.gitignore', '*');

        Storage::fake('public');
        $sind = rtrim(Storage::disk('public')->path(''), '/');
        $put($sind, 'chat-attachments/own.jpg', 'southind own');
        $put($sind, 'banners/b.png', 'a different southind file');

        $this->import(['--adda-storage' => $adda])
            ->expectsOutputToContain('Adda media folder')
            ->assertSuccessful();
        $this->assertFileDoesNotExist("{$sind}/deposit-receipts/r.jpg");

        $this->import(['--adda-storage' => $adda, '--execute' => true, '--confirm' => 'WIPE-SOUTHIND'])
            ->expectsOutputToContain('Import complete')
            ->assertSuccessful();

        $this->assertSame('receipt r', File::get("{$sind}/deposit-receipts/r.jpg"));
        $this->assertSame(1_790_000_000, filemtime("{$sind}/deposit-receipts/r.jpg"));
        $this->assertSame('voice', File::get("{$sind}/chat-voice/v.webm"));
        $this->assertFileExists("{$sind}/banners/old-unused.png");
        $this->assertFileDoesNotExist("{$sind}/.gitignore");
        // Never overwrites, never deletes.
        $this->assertSame('a different southind file', File::get("{$sind}/banners/b.png"));
        $this->assertSame('southind own', File::get("{$sind}/chat-attachments/own.jpg"));

        $report = json_decode(File::get(File::glob(storage_path('app/adda-import/report-*.json'))[0]), true);
        $this->assertSame(5, $report['result']['media']['copy']['copied']);
        $this->assertSame(1, $report['result']['media']['copy']['conflicts']);
        $this->assertSame(['deposits.receipt_image_path' => 1], $report['result']['media']['southind_missing']['missing']);

        File::deleteDirectory($adda);
    }

    public function test_running_twice_gives_the_same_result(): void
    {
        $this->seedAdda();

        $this->import(['--execute' => true, '--confirm' => 'WIPE-SOUTHIND'])->assertSuccessful();
        $first = [DB::table('users')->count(), DB::table('deposits')->count(), DB::table('legacy_adda_rows')->count(), DB::table('admins')->count()];

        $this->import(['--execute' => true, '--confirm' => 'WIPE-SOUTHIND'])->assertSuccessful();
        $second = [DB::table('users')->count(), DB::table('deposits')->count(), DB::table('legacy_adda_rows')->count(), DB::table('admins')->count()];

        $this->assertSame($first, $second);
    }

    public function test_an_unknown_adda_table_blocks_the_import(): void
    {
        $this->seedAdda();
        Schema::connection(self::SRC)->create('hand_made_table', fn (Blueprint $t) => $t->id());

        $this->import(['--execute' => true, '--confirm' => 'WIPE-SOUTHIND'])
            ->expectsOutputToContain('hand_made_table')
            ->assertFailed();

        $this->assertSame('MAIN', DB::table('branches')->value('code'));
    }

    public function test_a_deposit_pointing_at_a_missing_account_blocks_the_import(): void
    {
        $this->seedAdda();
        $this->src()->statement('PRAGMA foreign_keys = OFF');
        $this->src()->table('deposits')->insert(['id' => 1003, 'user_id' => 100, 'account_id' => 999, 'branch_id' => 2, 'play_id' => 'P100', 'amount' => 1, 'status' => 'approved']);

        $this->import()->expectsOutputToContain('deposits.account_id')->assertFailed();
    }
}
