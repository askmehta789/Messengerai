<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('page_id')->unique();
            $table->string('name');
            $table->text('access_token');
            $table->text('picture_url')->nullable();
            $table->boolean('ai_enabled')->default(true);
            $table->text('ai_instructions')->nullable();
            $table->boolean('subscribed')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('psid');
            $table->string('customer_name')->nullable();
            $table->text('customer_pic')->nullable();
            $table->text('last_message_text')->nullable();
            $table->string('last_message_from')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('ai_enabled')->default(true);
            $table->timestamp('ai_paused_until')->nullable();
            $table->boolean('needs_human')->default(false);
            $table->string('status')->default('open');
            $table->string('ad_id')->nullable();
            $table->text('ad_title')->nullable();
            $table->json('ad_context')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'psid']);
            $table->index('last_message_at');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('mid')->nullable()->unique();
            $table->string('direction'); // in | out
            $table->string('sender_type'); // customer | ai | agent | business_suite
            $table->text('text')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('sent'); // sent | failed
            $table->text('error')->nullable();
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('settings');
    }
};
