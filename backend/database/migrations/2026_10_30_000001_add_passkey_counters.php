<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// حماية الرواتب بخطوتين (المهمة 10): عدّاد توقيع مفتاح المرور وآخر استخدام
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passkeys', function (Blueprint $t) {
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('passkeys', fn (Blueprint $t) => $t->dropColumn(['sign_count', 'last_used_at']));
    }
};
