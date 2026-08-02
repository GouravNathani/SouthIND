<?php

use App\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Admin\AppSettingController as AdminAppSettingController;
use App\Http\Controllers\Admin\AdminManagementController;
use App\Http\Controllers\Admin\BannerController as AdminBannerController;
use App\Http\Controllers\Admin\BonusCodeController as AdminBonusCodeController;
use App\Http\Controllers\Admin\PushSubscriptionController;
use App\Http\Controllers\Admin\DepositController as AdminDepositController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\SupportChatController as AdminSupportChatController;
use App\Http\Controllers\Admin\TagController as AdminTagController;
use App\Http\Controllers\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\UserMpinController as AdminUserMpinController;
use App\Http\Controllers\Admin\WalletStatusController as AdminWalletStatusController;
use App\Http\Controllers\Admin\WhatsAppInboxController as AdminWhatsAppInboxController;
use App\Http\Controllers\Admin\WinnerStreakController as AdminWinnerStreakController;
use App\Http\Controllers\Admin\ReferralController as AdminReferralController;
use App\Http\Controllers\Auth\AdminAuthController;
use App\Http\Controllers\Auth\UserAuthController;
use App\Http\Controllers\PublicApi\AppSettingController as PublicAppSettingController;
use App\Http\Controllers\PublicApi\BannerController as PublicBannerController;
use App\Http\Controllers\PublicApi\GlobalSettingController as PublicGlobalSettingController;
use App\Http\Controllers\User\AccountController as UserAccountController;
use App\Http\Controllers\User\BonusCodeController as UserBonusCodeController;
use App\Http\Controllers\User\DepositController as UserDepositController;
use App\Http\Controllers\User\PushSubscriptionController as UserPushSubscriptionController;
use App\Http\Controllers\User\SupportChatController as UserSupportChatController;
use App\Http\Controllers\Super\SuperAuthController;
use App\Http\Controllers\Super\BonusCodeController as SuperBonusCodeController;
use App\Http\Controllers\Super\BranchController as SuperBranchController;
use App\Http\Controllers\Super\DashboardMetricsController;
use App\Http\Controllers\Super\DepositController as SuperDepositController;
use App\Http\Controllers\Super\GlobalSettingController as SuperGlobalSettingController;
use App\Http\Controllers\Super\PayoutController as SuperPayoutController;
use App\Http\Controllers\Super\SupportChatController as SuperSupportChatController;
use App\Http\Controllers\Super\WalletController as SuperWalletController;
use App\Http\Controllers\Super\TagController as SuperTagController;
use App\Http\Controllers\Super\UserManagementController as SuperUserManagementController;
use App\Http\Controllers\Super\WalletStatusController as SuperWalletStatusController;
use App\Http\Controllers\Super\WithdrawalController as SuperWithdrawalController;
use App\Http\Controllers\Super\WhatsAppAccountController as SuperWhatsAppAccountController;
use App\Http\Controllers\Super\WhatsAppInboxController as SuperWhatsAppInboxController;
use App\Http\Controllers\Super\WinnerStreakController as SuperWinnerStreakController;
use App\Http\Controllers\Super\ReferralController as SuperReferralController;
use App\Http\Controllers\User\WinnerStreakController as UserWinnerStreakController;
use App\Http\Controllers\User\ReferralController as UserReferralController;
use App\Http\Controllers\Staff\StaffAuthController;
use App\Http\Controllers\User\WithdrawalController as UserWithdrawalController;
use App\Http\Controllers\Webhook\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('banners', [PublicBannerController::class, 'index']);
Route::get('app-settings', [PublicAppSettingController::class, 'show']);
Route::get('global-settings', [PublicGlobalSettingController::class, 'show']);
// Signed with the shared wallet key (same gate as the web-group route above) —
// these were previously open to anyone who knew the path.
Route::middleware('wallet-hmac')->group(function () {
    Route::post('maintenance/refresh-cache', MaintenanceController::class);
    Route::post('maintenance/clear-cache', MaintenanceController::class);
});

// User-facing routes are gated by the maintenance toggle. When a super admin turns
// on user_panel_maintenance_enabled these return 503; the public global-settings
// endpoint (above) stays open so the User app can detect it and show a screen.
// Admin/Super/Staff panels are intentionally NOT wrapped and stay available.
Route::middleware('user-panel-open')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('mpin-login', [UserAuthController::class, 'loginWithMpin']);
        Route::post('check-branch', [UserAuthController::class, 'checkBranch']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [UserAuthController::class, 'logout']);
            Route::get('me', [UserAuthController::class, 'me']);
            Route::post('mpin/verify', [UserAuthController::class, 'verifyMpin']);
            Route::post('mpin/change', [UserAuthController::class, 'changeMpin']);
        });
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('accounts', [UserAccountController::class, 'index']);
        Route::get('deposits', [UserDepositController::class, 'index']);
        Route::get('withdrawals', [UserWithdrawalController::class, 'index']);
        Route::post('deposits', [UserDepositController::class, 'store']);
        Route::post('withdrawals', [UserWithdrawalController::class, 'store']);

        Route::post('push/subscribe', [UserPushSubscriptionController::class, 'store']);
        Route::delete('push/subscribe', [UserPushSubscriptionController::class, 'destroy']);

        // Support chat (User <-> support team).

        // Bonus codes. Redeem/check are throttled — the code is the only secret,
        // so an unthrottled endpoint would let a client enumerate live codes.
        Route::get('bonus/redemptions', [UserBonusCodeController::class, 'index']);
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('bonus/check', [UserBonusCodeController::class, 'check']);
            Route::post('bonus/redeem', [UserBonusCodeController::class, 'redeem']);
        });

        Route::get('support/chat', [UserSupportChatController::class, 'show']);
        Route::post('support/chat', [UserSupportChatController::class, 'send']);

        // Winner Streak — recent winners for the caller's own branch. Reads
        // frozen entries only, and only the fields the branch admin published.
        Route::get('winner-streak/recent', [UserWinnerStreakController::class, 'recent']);

        // Referral / Commission Account. Presented to the user simply as
        // "Account" — see App\Http\Controllers\User\ReferralController.
        Route::get('referral/account', [UserReferralController::class, 'account']);
        Route::get('referral/team', [UserReferralController::class, 'team']);
        Route::get('referral/entries', [UserReferralController::class, 'entries']);
        Route::get('referral/payouts', [UserReferralController::class, 'payouts']);
        Route::post('referral/payouts', [UserReferralController::class, 'requestPayout']);
        // Throttled: entering codes is the one write here a script could probe
        // to enumerate valid codes.
        Route::post('referral/apply-code', [UserReferralController::class, 'applyCode'])
            ->middleware('throttle:10,1');
    });
});

// WhatsApp Cloud API webhook (Meta). Public, unauthenticated — the payload's
// phone_number_id selects the matching account.
Route::get('whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('whatsapp/webhook', [WhatsAppWebhookController::class, 'receive']);

Route::prefix('admin')->group(function () {
    Route::post('login', [AdminAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::post('logout', [AdminAuthController::class, 'logout']);
        Route::get('me', [SuperAuthController::class, 'me']);
        // Expiry popup poll — outside prevent-staff so staff see the lock too.
        Route::get('wallet-status', [AdminWalletStatusController::class, 'show']);

        Route::apiResource('banners', AdminBannerController::class);
        Route::post('push/subscribe', [PushSubscriptionController::class, 'store']);
        Route::delete('push/subscribe', [PushSubscriptionController::class, 'destroy']);
        Route::apiResource('accounts', AdminAccountController::class);
        Route::apiResource('app-settings', AdminAppSettingController::class)
            ->only(['index', 'store', 'show', 'update']);

        // User tags (branch-scoped). Outside prevent-staff so support staff can
        // tag users, even though broader user CRUD stays admin-only below.
        Route::apiResource('tags', AdminTagController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::put('users/{user}/tags', [AdminTagController::class, 'syncUser']);

        // Support chat (User <-> support team). Accessible to support staff too.
        // Bonus codes. Money-adjacent, so staff accounts are excluded.
        Route::middleware('prevent-staff')->group(function () {
            Route::post('bonus-codes/generate', [AdminBonusCodeController::class, 'generate']);
            Route::get('bonus-codes/redemptions', [AdminBonusCodeController::class, 'redemptions']);
            Route::patch('bonus-codes/redemptions/{redemption}', [AdminBonusCodeController::class, 'updateRedemption']);
            Route::get('bonus-codes/users/{user}/redemptions', [AdminBonusCodeController::class, 'userHistory']);
            Route::apiResource('bonus-codes', AdminBonusCodeController::class);

            // Winner Streak. Money-adjacent (rewards + full P/L of every user),
            // so staff accounts are excluded like bonus codes.
            Route::get('winner-streak', [AdminWinnerStreakController::class, 'index']);
            Route::patch('winner-streak/entries/{entry}', [AdminWinnerStreakController::class, 'updateEntry']);
            Route::get('winner-streak/{period}', [AdminWinnerStreakController::class, 'show']);
            Route::put('winner-streak/{period}', [AdminWinnerStreakController::class, 'update']);
            Route::get('winner-streak/{period}/history', [AdminWinnerStreakController::class, 'history']);
            Route::post('winner-streak/cycles/{cycle}/recalculate', [AdminWinnerStreakController::class, 'recalculate']);
            Route::post('winner-streak/{period}/reset', [AdminWinnerStreakController::class, 'reset']);

            // Referral & commission. Behind prevent-staff with the other money
            // features — staff can process deposits but never move agent money.
            Route::get('referral', [AdminReferralController::class, 'index']);
            Route::put('referral/settings', [AdminReferralController::class, 'updateSettings']);
            Route::get('referral/agents', [AdminReferralController::class, 'agents']);
            Route::get('referral/agents/{user}', [AdminReferralController::class, 'agent']);
            Route::patch('referral/agents/{user}', [AdminReferralController::class, 'updateAgent']);
            Route::post('referral/agents/{user}/regenerate-code', [AdminReferralController::class, 'regenerateCode']);
            Route::post('referral/attach', [AdminReferralController::class, 'attach']);
            Route::post('referral/users/{user}/detach', [AdminReferralController::class, 'detach']);
            Route::get('referral/entries', [AdminReferralController::class, 'entries']);
            Route::get('referral/entries/export', [AdminReferralController::class, 'export']);
            Route::post('referral/entries/{entry}/resolve', [AdminReferralController::class, 'resolveEntry']);
            Route::post('referral/adjust', [AdminReferralController::class, 'adjust']);
            Route::get('referral/payouts', [AdminReferralController::class, 'payouts']);
            Route::post('referral/payouts', [AdminReferralController::class, 'advancePayout']);
            Route::patch('referral/payouts/{payout}', [AdminReferralController::class, 'processPayout']);
            Route::get('referral/audits', [AdminReferralController::class, 'audits']);
        });

        Route::get('support/conversations', [AdminSupportChatController::class, 'conversations']);
        Route::get('support/conversations/{conversation}', [AdminSupportChatController::class, 'show']);
        Route::post('support/conversations/{conversation}/messages', [AdminSupportChatController::class, 'send']);
        Route::patch('support/conversations/{conversation}/messages/{message}', [AdminSupportChatController::class, 'update']);
        Route::delete('support/conversations/{conversation}/messages/{message}', [AdminSupportChatController::class, 'destroy']);
        Route::patch('support/conversations/{conversation}/status', [AdminSupportChatController::class, 'updateStatus']);

        // WhatsApp inbox (Cloud API). Admins chat with contacts using the accounts
        // a super admin configured; account/token management stays super-admin only.
        Route::get('whatsapp/accounts', [AdminWhatsAppInboxController::class, 'accounts']);
        Route::get('whatsapp/templates', [AdminWhatsAppInboxController::class, 'templates']);
        Route::get('whatsapp/conversations', [AdminWhatsAppInboxController::class, 'conversations']);
        Route::get('whatsapp/conversations/{conversation}', [AdminWhatsAppInboxController::class, 'conversation']);
        Route::post('whatsapp/send', [AdminWhatsAppInboxController::class, 'send']);

        Route::middleware('prevent-staff')->group(function () {
            Route::apiResource('staff', StaffController::class);
            Route::get('users', [AdminUserController::class, 'index']);
            Route::get('users/{user}', [AdminUserController::class, 'show']);
            Route::post('users', [AdminUserController::class, 'store']);
            Route::patch('users/{user}', [AdminUserController::class, 'update']);
            Route::post('users/{user}/mpin', [AdminUserMpinController::class, 'store']);
            Route::patch('users/{user}/status', [AdminUserController::class, 'updateStatus']);
        });

        Route::apiResource('deposits', AdminDepositController::class)
            ->only(['index', 'show', 'destroy']);
        Route::patch('deposits/{deposit}/status', [AdminDepositController::class, 'updateStatus']);

        Route::apiResource('withdrawals', AdminWithdrawalController::class)
            ->only(['index', 'show', 'destroy']);
        Route::patch('withdrawals/{withdrawal}/status', [AdminWithdrawalController::class, 'updateStatus']);
    });
});

Route::prefix('super')->group(function () {
    Route::post('login', [SuperAuthController::class, 'login']);
    Route::middleware(['auth:sanctum', 'super-admin'])->group(function () {
        Route::get('me', [AdminAuthController::class, 'me']);
        Route::post('logout', [SuperAuthController::class, 'logout']);
        Route::get('wallet-status', [SuperWalletStatusController::class, 'show']);
        Route::get('payout/monthly', [SuperPayoutController::class, 'monthly'])
            ->name('super.payout.monthly');
        Route::get('payout/daily', [SuperPayoutController::class, 'daily'])
            ->name('super.payout.daily');
        Route::get('wallet', [SuperWalletController::class, 'show'])
            ->name('super.wallet.show');
        Route::get('wallet/daily', [SuperWalletController::class, 'daily'])
            ->name('super.wallet.daily');
        Route::post('wallet/settle', [SuperWalletController::class, 'settle'])
            ->name('super.wallet.settle');
        Route::patch('password', [SuperAuthController::class, 'updatePassword']);
        Route::apiResource('admins', AdminManagementController::class);
        Route::apiResource('branches', SuperBranchController::class);
        Route::get('global-settings', [SuperGlobalSettingController::class, 'show']);
        Route::patch('global-settings', [SuperGlobalSettingController::class, 'update']);
        Route::get('users', [SuperUserManagementController::class, 'index']);
        Route::post('users', [SuperUserManagementController::class, 'store']);
        Route::get('users/{user}', [SuperUserManagementController::class, 'show']);
        Route::patch('users/{user}', [SuperUserManagementController::class, 'update']);
        Route::patch('users/{user}/status', [SuperUserManagementController::class, 'updateStatus']);
        Route::post('users/{user}/mpin', [SuperUserManagementController::class, 'resetMpin']);
        Route::patch('users/{user}/branch', [SuperUserManagementController::class, 'updateBranch']);
        Route::put('users/{user}/tags', [SuperTagController::class, 'syncUser']);
        // User tags (branch-scoped; super admin passes branch_id).
        Route::apiResource('tags', SuperTagController::class)
            ->only(['index', 'store', 'update', 'destroy'])
            ->names('super.tags');
        // Support chat (User <-> support team) — super admin sees every branch.
        // Bonus codes — super admin also creates cross-branch ("global") codes.
        Route::post('bonus-codes/generate', [SuperBonusCodeController::class, 'generate']);
        Route::get('bonus-codes/redemptions', [SuperBonusCodeController::class, 'redemptions']);
        Route::patch('bonus-codes/redemptions/{redemption}', [SuperBonusCodeController::class, 'updateRedemption']);
        Route::get('bonus-codes/users/{user}/redemptions', [SuperBonusCodeController::class, 'userHistory']);
        Route::apiResource('bonus-codes', SuperBonusCodeController::class)
            ->names('super.bonus-codes');

        // Winner Streak — branch-wise. No branch_id on the index gives the
        // cross-branch summary; every other call requires one.
        Route::get('winner-streak', [SuperWinnerStreakController::class, 'index']);
        Route::patch('winner-streak/entries/{entry}', [SuperWinnerStreakController::class, 'updateEntry']);
        Route::get('winner-streak/{period}', [SuperWinnerStreakController::class, 'show']);
        Route::put('winner-streak/{period}', [SuperWinnerStreakController::class, 'update']);
        Route::get('winner-streak/{period}/history', [SuperWinnerStreakController::class, 'history']);
        Route::post('winner-streak/cycles/{cycle}/recalculate', [SuperWinnerStreakController::class, 'recalculate']);
        Route::post('winner-streak/{period}/reset', [SuperWinnerStreakController::class, 'reset']);

        // Referral & commission. Identical surface to the admin panel; the
        // branch comes from ?branch_id, and index without one is a roll-up.
        Route::get('referral', [SuperReferralController::class, 'index']);
        Route::put('referral/settings', [SuperReferralController::class, 'updateSettings']);
        Route::get('referral/agents', [SuperReferralController::class, 'agents']);
        Route::get('referral/agents/{user}', [SuperReferralController::class, 'agent']);
        Route::patch('referral/agents/{user}', [SuperReferralController::class, 'updateAgent']);
        Route::post('referral/agents/{user}/regenerate-code', [SuperReferralController::class, 'regenerateCode']);
        Route::post('referral/attach', [SuperReferralController::class, 'attach']);
        Route::post('referral/users/{user}/detach', [SuperReferralController::class, 'detach']);
        Route::get('referral/entries', [SuperReferralController::class, 'entries']);
        Route::get('referral/entries/export', [SuperReferralController::class, 'export']);
        Route::post('referral/entries/{entry}/resolve', [SuperReferralController::class, 'resolveEntry']);
        Route::post('referral/adjust', [SuperReferralController::class, 'adjust']);
        Route::get('referral/payouts', [SuperReferralController::class, 'payouts']);
        Route::post('referral/payouts', [SuperReferralController::class, 'advancePayout']);
        Route::patch('referral/payouts/{payout}', [SuperReferralController::class, 'processPayout']);
        Route::get('referral/audits', [SuperReferralController::class, 'audits']);

        Route::get('support/conversations', [SuperSupportChatController::class, 'conversations']);
        Route::get('support/conversations/{conversation}', [SuperSupportChatController::class, 'show']);
        Route::post('support/conversations/{conversation}/messages', [SuperSupportChatController::class, 'send']);
        Route::patch('support/conversations/{conversation}/messages/{message}', [SuperSupportChatController::class, 'update']);
        Route::delete('support/conversations/{conversation}/messages/{message}', [SuperSupportChatController::class, 'destroy']);
        Route::patch('support/conversations/{conversation}/status', [SuperSupportChatController::class, 'updateStatus']);
        // WhatsApp Cloud API (SuperAdmin — full account management + inbox)
        Route::get('whatsapp/accounts', [SuperWhatsAppAccountController::class, 'index']);
        Route::post('whatsapp/accounts', [SuperWhatsAppAccountController::class, 'store']);
        Route::patch('whatsapp/accounts/{whatsapp}', [SuperWhatsAppAccountController::class, 'update']);
        Route::delete('whatsapp/accounts/{whatsapp}', [SuperWhatsAppAccountController::class, 'destroy']);
        Route::post('whatsapp/accounts/{whatsapp}/sync-templates', [SuperWhatsAppAccountController::class, 'syncTemplates']);
        Route::get('whatsapp/templates', [SuperWhatsAppAccountController::class, 'templates']);
        Route::get('whatsapp/conversations', [SuperWhatsAppInboxController::class, 'conversations']);
        Route::get('whatsapp/conversations/{conversation}', [SuperWhatsAppInboxController::class, 'conversation']);
        Route::post('whatsapp/send', [SuperWhatsAppInboxController::class, 'send']);
        Route::get('deposits', [SuperDepositController::class, 'index']);
        Route::get('deposits/{deposit}', [SuperDepositController::class, 'show']);
        Route::patch('deposits/{deposit}/status', [SuperDepositController::class, 'updateStatus']);
        Route::get('withdrawals', [SuperWithdrawalController::class, 'index']);
        Route::get('withdrawals/{withdrawal}', [SuperWithdrawalController::class, 'show']);
        Route::patch('withdrawals/{withdrawal}/status', [SuperWithdrawalController::class, 'updateStatus']);
        Route::get('dashboard/user-activity', [DashboardMetricsController::class, 'userActivity']);
        Route::get('dashboard/user-averages', [DashboardMetricsController::class, 'userAverages']);
        Route::post('maintenance/clear-cache', MaintenanceController::class);
    });
});

Route::prefix('staff')->group(function () {
    Route::post('login', [StaffAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'staff'])->group(function () {
        Route::post('logout', [StaffAuthController::class, 'logout']);
        Route::get('me', [StaffAuthController::class, 'me']);

        Route::apiResource('banners', AdminBannerController::class)->names('staff.banners');
        Route::apiResource('accounts', AdminAccountController::class)->names('staff.accounts');
        Route::apiResource('app-settings', AdminAppSettingController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->names('staff.app-settings');
    });
});

// Unmatched API paths answer JSON, with the api middleware stack (no CSRF).
// routes/web.php's catch-all deliberately excludes api/ so requests reach here.
Route::fallback(fn () => response()->json(['message' => 'Not found.'], 404));
