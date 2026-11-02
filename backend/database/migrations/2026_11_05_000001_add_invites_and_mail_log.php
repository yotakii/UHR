<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 11e: روابط التفعيل وتعيين كلمة المرور بدل كلمات المرور المؤقتة، وسجل البريد الصادر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('invite_hash', 64)->nullable()->index();     // sha256 للرابط — الرابط نفسه لا يُحفظ
            $t->string('invite_kind', 10)->nullable();              // invite = تفعيل حساب جديد · reset = تعيين كلمة مرور
            $t->timestamp('invite_expires_at')->nullable();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('activated_at')->nullable();              // أول تعيين لكلمة المرور أو أول دخول
        });
        // الحسابات الحالية: من دخل أو لا يُلزم بتغيير كلمة مروره يُعدّ مفعّلاً
        DB::table('users')->where(fn ($q) => $q->whereNotNull('last_login_at')->orWhere('must_change_password', false))
            ->update(['activated_at' => DB::raw('coalesce(last_login_at, created_at)')]);

        Schema::create('mail_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('to', 190);
            $t->string('subject', 300)->nullable();
            $t->string('kind', 60)->nullable();                     // اسم الإشعار (AccountInvite، CandidateMessage…)
            $t->string('status', 10)->default('sending');           // sending | sent | failed
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'created_at']);
            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['invite_hash', 'invite_kind', 'invite_expires_at', 'invited_at', 'activated_at']));
    }
};
