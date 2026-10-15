<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأساس: المستخدمون والأدوار، الإعدادات، العدّادات، الملفات، السجلات، الطوابير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();          // admin, hr_director, hr_officer, finance, executive, ceo, manager, employee, candidate
            $t->string('name_ar');
            $t->string('name_en');
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('password');
            $t->string('name')->nullable();           // لحسابات بلا موظف (مدير النظام)
            $t->string('locale', 2)->default('ar');
            $t->string('theme', 10)->default('slate');
            $t->boolean('must_change_password')->default(false);
            $t->unsignedSmallInteger('failed_logins')->default(0);
            $t->timestamp('locked_until')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->boolean('active')->default(true);
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('role_user', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'user_id']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        // مفتاح/قيمة: بيانات الشركة، البادئات، قواعد الأتمتة، مهل المراحل…
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->jsonb('value');
            $t->timestamps();
        });

        // سياسات الدوام والإجازات بتاريخ نفاذ (SRS §7)
        Schema::create('policies', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80);
            $t->jsonb('value');
            $t->date('effective_from');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['key', 'effective_from']);
        });

        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->date('date')->unique();
            $t->string('name_ar');
            $t->string('name_en');
            $t->timestamps();
        });

        // عدّادات الترقيم (UHR-1xxx، UNQ-…-APP، QR-…، AST-…)؛ تُقفل بالصف عند الزيادة
        Schema::create('counters', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->unsignedBigInteger('value');
        });

        Schema::create('files', function (Blueprint $t) {
            $t->id();
            $t->string('disk', 20)->default('local');
            $t->string('path');
            $t->string('name');
            $t->unsignedBigInteger('size')->default(0);
            $t->string('mime', 120)->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // سجل الأحداث المعروض في Timeline لكل متقدم وموظف
        Schema::create('event_logs', function (Blueprint $t) {
            $t->id();
            $t->morphs('subject');
            $t->timestamp('at');
            $t->text('text_ar');
            $t->text('text_en');
            $t->string('kind', 10)->default('info');   // ok, info, warn, danger
            $t->string('form', 10)->nullable();        // F-28 …
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->index(['subject_type', 'subject_id', 'at']);
        });

        // سجل التدقيق: من، ماذا، متى، القيمة قبل/بعد
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 80);
            $t->nullableMorphs('subject');
            $t->jsonb('before')->nullable();
            $t->jsonb('after')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('at')->useCurrent();
            $t->index(['action', 'at']);
        });

        Schema::create('jobs', function (Blueprint $t) {
            $t->id();
            $t->string('queue')->index();
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'jobs', 'audit_logs', 'event_logs', 'files', 'counters', 'holidays', 'policies', 'settings', 'password_reset_tokens', 'role_user', 'users', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
