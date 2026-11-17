<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعة 18 — نظام الترميز (SRS الأنظمة الأساسية، الجزء A):
 * القوائم المرجعية ورموزها، طلبات التغيير وإعادة الهيكلة، جدول الربط، إصدارات الدليل،
 * وحقول الرموز في ملف الموظف (الدرجة، المسمى، فئة العامل، نوع العقد، مركز الكلفة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_lists', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();   // null = قائمة نظامية مشتركة (عملات، دول، محافظات، أيام)
            $t->string('key', 40);
            $t->string('name_ar'); $t->string('name_en');
            $t->string('group', 20)->default('org');          // org | people | pay | ops | docs | system
            $t->string('owner_role', 30)->nullable();          // مالك القائمة (يعتمد طلبات تغييرها)
            $t->string('pattern', 120)->nullable();            // تعبير نمطي للرمز
            $t->string('example', 40)->nullable();
            $t->boolean('hierarchical')->default(false);
            $t->string('parent_key', 40)->nullable();          // قائمة الأب (المسمى ← العائلة الوظيفية)
            $t->string('source', 30)->nullable();              // null = رموز الدليل | departments | locations (قوائم مرتبطة بجداولها)
            $t->boolean('system')->default(false);
            $t->boolean('required')->default(false);           // إلزامية لبوابة التأسيس
            $t->jsonb('attrs_schema')->nullable();             // خصائص إضافية [{key, ar, en, type, options?}]
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'key']);
        });

        Schema::create('code_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('code_list_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name_ar'); $t->string('name_en');
            $t->foreignId('parent_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->text('description')->nullable();
            $t->jsonb('attrs')->nullable();
            $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable();
            $t->string('status', 12)->default('active');      // draft | active | inactive | replaced
            $t->unsignedInteger('sort')->default(0);
            $t->string('legacy_code', 60)->nullable();
            $t->timestamp('used_at')->nullable();              // أول استخدام ← الرمز مقفل
            $t->foreignId('replaced_by_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->timestamps();
            $t->unique(['code_list_id', 'code']);              // لا يُعاد استخدام رمز ولو كان معطلاً
            $t->index(['company_id', 'code_list_id', 'status']);
        });

        Schema::create('code_change_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('list_key', 40);
            $t->unsignedBigInteger('item_id')->nullable();     // رمز الدليل أو صف الإدارة/الفرع حسب المصدر
            $t->string('type', 16);                            // add | edit | deactivate | reactivate | merge | move
            $t->jsonb('payload');
            $t->text('reason');
            $t->date('effective_on');
            $t->jsonb('impact')->nullable();
            $t->string('status', 12)->default('pending');     // pending | approved | rejected | applied | cancelled | failed
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->string('version', 12)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        Schema::create('code_mappings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('list_key', 40);
            $t->unsignedBigInteger('old_id'); $t->string('old_code', 40);
            $t->unsignedBigInteger('new_id')->nullable(); $t->string('new_code', 40)->nullable();
            $t->string('operation', 10);                       // merge | move
            $t->date('effective_on');
            $t->jsonb('moved')->nullable();                    // عدد السجلات المنقولة لكل جدول
            $t->foreignId('change_request_id')->nullable()->constrained('code_change_requests')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('codebook_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('version', 12);
            $t->unsignedSmallInteger('major'); $t->unsignedSmallInteger('minor');
            $t->text('summary');
            $t->jsonb('snapshot');
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at');
            $t->timestamps();
            $t->unique(['company_id', 'version']);
        });

        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('grade_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->foreignId('job_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->foreignId('worker_category_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->foreignId('contract_type_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->foreignId('cost_center_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->string('legacy_code', 40)->nullable();
        });
        Schema::table('form_templates', fn (Blueprint $t) => $t->string('legacy_code', 60)->nullable());
        Schema::table('policy_documents', fn (Blueprint $t) => $t->string('legacy_code', 60)->nullable());

        // القوائم النظامية + قوائم كل شركة قائمة، ودليل معتمد 1.0 للشركات العاملة كي لا تتوقف (بوابة التأسيس للشركات الجديدة فقط)
        \App\Support\Audit::mute(function () {
            \App\Services\Codes\CodeCatalog::seedSystem();
            foreach (\App\Models\Company::all() as $c) \App\Support\Tenant::run($c, function () use ($c) {
                \App\Services\Codes\CodeCatalog::seedCompany();
                \App\Services\Codes\CodeCatalog::backfillEmployees();
                \App\Support\Forms::seed();
                if (! \App\Models\CodebookVersion::exists()) \App\Services\Codes\Codebook::snapshotVersion(null, 'الإصدار الأول — اعتُمد تلقائياً للشركة العاملة عند إضافة دليل الترميز.', 'First version — approved automatically for an existing company when the codebook was added.', true);
            });
        });
    }

    public function down(): void
    {
        Schema::table('policy_documents', fn (Blueprint $t) => $t->dropColumn('legacy_code'));
        Schema::table('form_templates', fn (Blueprint $t) => $t->dropColumn('legacy_code'));
        Schema::table('employees', function (Blueprint $t) {
            foreach (['grade_id', 'job_id', 'worker_category_id', 'contract_type_id', 'cost_center_id'] as $c) $t->dropConstrainedForeignId($c);
            $t->dropColumn('legacy_code');
        });
        foreach (['codebook_versions', 'code_mappings', 'code_change_requests', 'code_items', 'code_lists'] as $t) Schema::dropIfExists($t);
    }
};
