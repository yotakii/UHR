<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** تطبيق الجوال: أجهزة الإشعارات (رمز Firebase لكل جهاز مسجّل دخوله) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->string('token', 512)->unique();
            $t->string('platform', 10)->default('android');
            $t->string('app_version', 20)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
            $t->index('user_id');
        });
    }

    public function down(): void { Schema::dropIfExists('push_devices'); }
};
