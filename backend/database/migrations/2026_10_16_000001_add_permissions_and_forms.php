<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأساس — المرحلة 1 (SRS §10.2، §10.4، §10.5):
 * - role_permissions: مصفوفة الصلاحيات لكل شركة (دور × وحدة × إجراءات × نطاق). الخلية الغائبة = القيمة الافتراضية من App\Support\Perm.
 * - form_templates: سجل النماذج (الرمز، الإصدار، تاريخه) لتذييل الطباعة، وعلامة «حساس» لتسجيل طباعته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('role', 30);
            $t->string('module', 30);
            $t->jsonb('actions');                 // ["view","create",…]
            $t->string('scope', 6)->default('all');   // own | team | all
            $t->timestamps();
            $t->unique(['company_id', 'role', 'module']);
        });

        Schema::create('form_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40);               // F-28 · HR.01-01-F-03
            $t->string('ref', 80);                // الرمز المطبوع: Uniqueness-HR-01-F-28 · Uniqueness-HR.01-01-F-03
            $t->string('title_ar');
            $t->string('title_en');
            $t->string('module', 30)->nullable();
            $t->string('issue', 10)->default('01');
            $t->date('issued_on');
            $t->boolean('sensitive')->default(false);   // طباعته تُسجَّل في التدقيق
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
        Schema::dropIfExists('role_permissions');
    }
};
