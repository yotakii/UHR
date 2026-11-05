<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 12b: «شكر زميل» بشارات قيم الشركة ونقاط اختيارية، والاحتفالات التلقائية في الساحة (أعياد الميلاد، سنويات الخدمة، المنضمون الجدد، الترقيات) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('space_posts', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable()->change();   // منشورات النظام (الاحتفالات) بلا كاتب
            $t->jsonb('meta')->nullable();
        });
        Schema::table('employees', function (Blueprint $t) { $t->boolean('hide_birthday')->default(false); });

        Schema::create('praises', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_user_id')->constrained('users');
            $t->string('value', 40);
            $t->text('message');
            $t->jsonb('recipients');          // [employee_id…]
            $t->unsignedInteger('points')->default(0);   // لكل مستلم
            $t->foreignId('space_post_id')->nullable()->constrained('space_posts')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'from_user_id', 'created_at']);
        });

        Schema::create('celebrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);            // birthday|anniversary|joiner|promotion
            $t->date('on');
            $t->string('key', 80);             // birthday:2026-11-05 · promotion:12:2026-11-05
            $t->jsonb('employees');
            $t->foreignId('space_post_id')->nullable()->constrained('space_posts')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('celebrations');
        Schema::dropIfExists('praises');
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn('hide_birthday'));
        Schema::table('space_posts', fn (Blueprint $t) => $t->dropColumn('meta'));
    }
};
