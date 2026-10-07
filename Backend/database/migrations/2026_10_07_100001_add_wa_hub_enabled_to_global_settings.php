<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ON/OFF switch for WA Hub (WhatsApp messages from each branch's own linked
 * number), set by the super admin only. OFF blocks the admin and super WA Hub
 * endpoints and stops event messages from being queued. Default OFF, so
 * nothing changes until it is switched on. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('global_settings') && !Schema::hasColumn('global_settings', 'wa_hub_enabled')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->boolean('wa_hub_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('global_settings', 'wa_hub_enabled')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->dropColumn('wa_hub_enabled');
            });
        }
    }
};
