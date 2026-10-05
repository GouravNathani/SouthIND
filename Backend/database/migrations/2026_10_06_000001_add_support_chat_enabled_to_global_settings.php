<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ON/OFF switch for the whole support chat (User <-> branch admins), set by
 * the super admin only. OFF blocks the user and admin chat endpoints so no
 * panel keeps polling them; old conversations are kept. Default ON, so
 * nothing changes until it is switched off. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('global_settings') && !Schema::hasColumn('global_settings', 'support_chat_enabled')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->boolean('support_chat_enabled')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('global_settings', 'support_chat_enabled')) {
            Schema::table('global_settings', function (Blueprint $table) {
                $table->dropColumn('support_chat_enabled');
            });
        }
    }
};
