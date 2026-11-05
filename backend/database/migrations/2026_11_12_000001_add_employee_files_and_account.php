<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الملاحظات 15e:
 * 49 توقيع الموظف وبصمته عند المباشرة، وربط ملفات موجودة في النظام بملفه.
 * 63 ربط بنود قائمة المباشرة F-19 بملفات فعلية.
 * 74 مستندات تحتاج توقيع الموظف بموعد، وتنبيه قبل الموعد.
 * 54 نص قابل للتعديل في النماذج المطبوعة (مقدمة وملاحظات).
 * 67 الصورة الشخصية للحساب.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_documents', function (Blueprint $t) {
            $t->string('source', 10)->default('upload');                 // upload | linked
            $t->unsignedSmallInteger('onboarding_item')->nullable();     // رقم بند F-19 المرتبط
            $t->boolean('needs_signature')->default(false);
            $t->date('sign_due')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->foreignId('signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamp('sign_alerted_at')->nullable();
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->foreignId('fingerprint_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamp('biometrics_at')->nullable();
        });
        Schema::table('form_templates', function (Blueprint $t) {
            $t->jsonb('intro')->nullable();    // {ar, en} فقرة بعد العنوان
            $t->jsonb('notes')->nullable();    // {ar, en} ملاحظات قبل التذييل
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('avatar_file_id')->nullable()->constrained('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('avatar_file_id'));
        Schema::table('form_templates', fn (Blueprint $t) => $t->dropColumn(['intro', 'notes']));
        Schema::table('employees', function (Blueprint $t) {
            $t->dropConstrainedForeignId('signature_file_id'); $t->dropConstrainedForeignId('fingerprint_file_id'); $t->dropColumn('biometrics_at');
        });
        Schema::table('employee_documents', function (Blueprint $t) {
            $t->dropConstrainedForeignId('signature_file_id');
            $t->dropColumn(['source', 'onboarding_item', 'needs_signature', 'sign_due', 'signed_at', 'sign_alerted_at']);
        });
    }
};
