<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support chat — one conversation per user, WhatsApp-style, with image and
 * voice attachments.
 *
 * Messages are soft-deleted rather than removed: an admin still sees a deleted
 * message struck through, while the user's own fetch omits it. `flagged` is set
 * automatically when a user pastes something that looks like a contact number,
 * which is the main way users try to move a deal off-platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status')->default('open');          // open | closed
            $table->boolean('flagged')->default(false);         // user shared a contact number
            $table->boolean('awaiting_reply')->default(false);  // newest message is an unreplied user message
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 255)->nullable();
            // Dedupes auto-replies when a user sends a burst of messages.
            $table->timestamp('last_auto_reply_at')->nullable();
            $table->unsignedInteger('user_unread_count')->default(0);
            $table->unsignedInteger('admin_unread_count')->default(0);
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // The support inbox: one branch, newest activity first.
            $table->index(['branch_id', 'last_message_at']);
            // The unanswered queue, and the pending badge the SuperAdmin polls.
            $table->index(['branch_id', 'status', 'awaiting_reply']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->string('sender_type');  // user | admin
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('body')->nullable();
            $table->string('image_path', 2048)->nullable();
            $table->string('audio_path', 2048)->nullable();
            $table->timestamp('read_at')->nullable();
            // Set when an admin edits the text, inside the allowed window.
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            // Deleted messages stay for struck-through display.
            $table->softDeletes();

            $table->index(['conversation_id', 'id']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
    }
};
