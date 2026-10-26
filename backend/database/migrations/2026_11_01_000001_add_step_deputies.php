<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// M16 سلاسل الاعتماد (11a): نائب لكل مستوى — يُنسخ مع الخطوة ويستطيع اتخاذ قرارها
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_steps', fn (Blueprint $t) => $t->foreignId('deputy_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('request_steps', fn (Blueprint $t) => $t->dropConstrainedForeignId('deputy_id'));
    }
};
