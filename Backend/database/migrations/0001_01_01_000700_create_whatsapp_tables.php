<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Cloud API — accounts, approved templates, and the inbox.
 *
 * A super admin registers the accounts (token stored encrypted via a model
 * cast); branch admins only use them. Inbound messages arrive on a public
 * webhook and are routed by `phone_number_id`, which is why that column is
 * unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->string('waba_id');
            // The webhook routes inbound payloads by this value.
            $table->string('phone_number_id')->unique();
            $table->text('access_token'); // encrypted via model cast
            $table->string('phone_number')->nullable();
            $table->string('webhook_verify_token')->nullable();
            $table->string('status')->default('active'); // active | inactive
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whatsapp_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->string('name');
            $table->string('language')->default('en_US');
            $table->string('category')->nullable();
            $table->string('status')->nullable(); // APPROVED | PENDING | REJECTED
            $table->json('components')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'name', 'language'], 'whatsapp_templates_unique');
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->string('contact_phone');
            $table->string('contact_name')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->string('last_message_preview', 255)->nullable();
            // Meta's 24-hour customer-service window is measured from this.
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'contact_phone'], 'whatsapp_conversations_unique');
            $table->index(['whatsapp_account_id', 'last_message_at']);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('whatsapp_conversations')->cascadeOnDelete();
            $table->string('wa_message_id')->nullable()->index();
            $table->string('direction');                 // inbound | outbound
            $table->string('type')->default('text');     // text | image | document | template
            $table->text('body')->nullable();
            $table->string('media_path', 2048)->nullable();
            $table->string('template_name')->nullable();
            $table->string('status')->nullable();        // pending | sent | delivered | read | failed
            $table->text('error')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
