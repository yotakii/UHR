<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المكافآت، الإشعارات، الإعلانات، الأداء، سلاسل الاعتماد، قنوات الإشعار، التحقق بخطوتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rewards', function (Blueprint $t) {
            $t->id();
            $t->string('title_ar');
            $t->string('title_en');
            $t->unsignedInteger('cost');
            $t->unsignedInteger('stock')->default(0);
            $t->string('kind', 20)->default('gift');    // leave, food, gym, course, parking, gift
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reward_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('cost');
            $t->string('status', 10)->default('pending');
            $t->string('decided_by')->nullable();
            $t->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // audience = hr: لكل مستخدمي الموارد البشرية (القراءة لكل مستخدم في notice_reads)
        // audience = user: لمستخدم واحد
        Schema::create('notices', function (Blueprint $t) {
            $t->id();
            $t->string('audience', 6);
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->text('text_ar');
            $t->text('text_en');
            $t->string('link', 60)->nullable();         // cand:12 / emp:7 / leaves / my-requests …
            $t->timestamp('at');
            $t->index(['audience', 'user_id', 'at']);
        });

        Schema::create('notice_reads', function (Blueprint $t) {
            $t->foreignId('notice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->primary(['notice_id', 'user_id']);
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->text('text_ar');
            $t->text('text_en');
            $t->string('tag', 20)->default('people');   // payroll, training, people
            $t->timestamp('published_at');
            $t->timestamp('archived_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('performance_cycles', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar');
            $t->string('name_en');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 10)->default('open');  // draft, open, closed
            $t->jsonb('weights');                       // {manager: .5, peers: .3, self: .2}
            $t->timestamps();
        });

        Schema::create('competencies', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar');
            $t->string('name_en');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('performance_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('performance_cycle_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('performance')->nullable();   // 1–3 (معايرة 9-Box)
            $t->unsignedTinyInteger('potential')->nullable();     // 1–3
            $t->jsonb('ratings');                       // {competency_id: {self, manager, peers}}
            $t->decimal('score', 3, 1)->nullable();
            $t->timestamps();
            $t->unique(['performance_cycle_id', 'employee_id']);
        });

        // سلاسل الاعتماد: leave, appointment, resignation
        Schema::create('approval_chains', function (Blueprint $t) {
            $t->id();
            $t->string('type', 20)->unique();
            $t->jsonb('levels');                        // [{role_ar, role_en, role_key, user_id?, deputy_id?}]
            $t->timestamps();
        });

        Schema::create('notification_channels', function (Blueprint $t) {
            $t->id();
            $t->string('key', 20)->unique();            // email, sms, whatsapp
            $t->boolean('enabled')->default(false);
            $t->text('config')->nullable();             // مشفّر
            $t->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60);
            $t->string('channel', 20);
            $t->text('body_ar');
            $t->text('body_en');
            $t->timestamps();
            $t->unique(['key', 'channel']);
        });

        // حماية الرواتب بخطوتين (SRS §4.4)
        Schema::create('mfa_factors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('totp_secret')->nullable();        // مشفّر
            $t->unsignedBigInteger('totp_last_step')->nullable();
            $t->jsonb('backup_hashes')->nullable();     // 8 رموز مجزّأة
            $t->timestamp('enrolled_at')->nullable();
            $t->timestamps();
        });

        Schema::create('passkeys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('credential_id')->unique();
            $t->text('public_key');
            $t->smallInteger('alg');
            $t->string('label')->nullable();
            $t->timestamps();
        });

        Schema::create('mfa_audit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->boolean('ok');
            $t->string('method', 10);                   // app, backup, phone, passkey, enrol
            $t->string('ip', 45)->nullable();
            $t->timestamp('at')->useCurrent();
            $t->index(['user_id', 'at']);
        });
    }

    public function down(): void
    {
        foreach (['mfa_audit', 'passkeys', 'mfa_factors', 'message_templates', 'notification_channels', 'approval_chains', 'performance_reviews', 'competencies', 'performance_cycles', 'announcements', 'notice_reads', 'notices', 'redemptions', 'rewards'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
