<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('update_id')->unique();
            $t->json('payload');
            $t->string('state')->default('pending');
            $t->timestamp('processed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        Schema::create('telegram_conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('owner_id')->unique();
            $t->bigInteger('chat_id');
            $t->string('mode')->default('auto');
            $t->timestamp('last_activity')->nullable();
            $t->timestamps();
        });
        Schema::create('telegram_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('telegram_conversations')->cascadeOnDelete();
            $t->string('role');
            $t->longText('content');
            $t->string('provider')->nullable();
            $t->string('model')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('telegram_conversations');
        Schema::dropIfExists('telegram_updates');
    }
};
