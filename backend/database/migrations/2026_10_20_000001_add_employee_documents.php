<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M07 — «الملفات الإضافية» في ملف الموظف (SRS §10.11، D-25): نوع من قائمة الإعدادات، تاريخ انتهاء، حساسية،
 * تنبيه قبل الانتهاء، والحالات مرفوع ← منتهي ← مؤرشف (لا حذف نهائي). المشاهدة والتنزيل في audit_logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30);                         // مفتاح من Settings docs.types
            $t->string('title')->nullable();
            $t->foreignId('file_id')->constrained('files');
            $t->date('expires_at')->nullable();
            $t->boolean('sensitive')->default(false);
            $t->string('status', 10)->default('active');    // active, expired, archived
            $t->text('note')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('alerted_at')->nullable();        // تنبيه «قارب على الانتهاء» أُرسل
            $t->timestamp('archived_at')->nullable();
            $t->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'employee_id', 'status']);
            $t->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
    }
};
