<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branch-level audit rows (referral settings changes) carry user_id = 0 because
     * no single user changed. The FK to users rejected them, so enabling or editing
     * referral settings failed with a 500. BC's table has no such FK.
     */
    public function up(): void
    {
        Schema::table('referral_audits', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
    }

    public function down(): void
    {
        // Rows with user_id = 0 may exist, so the FK is not restored.
    }
};
