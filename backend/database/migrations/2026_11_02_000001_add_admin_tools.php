<?php

use App\Support\Forms;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M16 (11b): النسخ الاحتياطي (نسخة الخادم الكاملة ونسخة بيانات الشركة)، مكتبة السياسات (D-26: وثيقة ← إصدارات ← فقرات للبحث
 * وربطها بالإعدادات المطبَّقة، وسجل الاطلاع)، وفهرس سجل التدقيق لكل شركة، ونموذج طباعة الهيكل (D-33).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete(); // null = نسخة الخادم الكاملة
            $t->string('kind', 10); // full | company
            $t->string('trigger', 10)->default('manual'); // auto | manual
            $t->string('status', 10)->default('running'); // running | ok | failed
            $t->string('path')->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->jsonb('meta')->nullable(); // tables, rows, files
            $t->text('error')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'kind', 'status']);
        });

        Schema::create('policy_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40)->nullable();
            $t->string('title_ar'); $t->string('title_en');
            $t->string('category', 20)->default('policy'); // policy | procedure | regulation | charter | other
            $t->string('status', 12)->default('draft'); // draft | published | archived
            $t->unsignedBigInteger('current_version_id')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });

        Schema::create('policy_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->constrained('policy_documents')->cascadeOnDelete();
            $t->unsignedSmallInteger('number');
            $t->string('issue', 20)->nullable(); // رقم الإصدار المطبوع على الوثيقة
            $t->date('effective_from')->nullable();
            $t->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->longText('text')->nullable();
            $t->boolean('text_missing')->default(false);
            $t->text('note')->nullable();
            $t->string('status', 12)->default('draft'); // draft | published | archived
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'number']);
        });

        Schema::create('policy_paragraphs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('version_id')->constrained('policy_versions')->cascadeOnDelete();
            $t->unsignedInteger('n');
            $t->text('text');
            $t->text('norm'); // نص مطبَّع للبحث (بلا تشكيل، توحيد الألف والياء والتاء المربوطة)
            $t->string('rule_key', 40)->nullable(); // الإعداد الذي يطبّق هذه الفقرة (يضبطه HR يدوياً)
            $t->string('rule_note', 300)->nullable();
            $t->index(['version_id', 'n']);
        });

        Schema::create('policy_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('version_id')->constrained('policy_versions')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at');
            $t->unique(['version_id', 'user_id']);
        });

        Schema::table('audit_logs', fn (Blueprint $t) => $t->index(['company_id', 'at']));

        foreach (DB::table('companies')->pluck('id') as $company) Tenant::run($company, fn () => Forms::seed());
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $t) => $t->dropIndex(['company_id', 'at']));
        foreach (['policy_reads', 'policy_paragraphs', 'policy_versions', 'policy_documents', 'backups'] as $t) Schema::dropIfExists($t);
    }
};
