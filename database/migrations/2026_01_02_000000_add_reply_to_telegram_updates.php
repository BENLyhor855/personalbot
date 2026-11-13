<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table) {
            $table->longText('reply')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table) {
            $table->dropColumn('reply');
        });
    }
};
