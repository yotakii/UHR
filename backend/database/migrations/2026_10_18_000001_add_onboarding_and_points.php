<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use App\Support\Forms;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المهمة 06b: إقرار ميثاق السلوك وتثبيت التعيين في سجل المباشرة (M06)،
 * وسجل النقاط للإحالة المرحلية 10/20/30/40 (SRS §10.9) — كل إضافة أو خصم بسببه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $t) {
            $t->foreignId('conduct_signature_file_id')->nullable()->after('f20_mother_name')->constrained('files')->nullOnDelete();
            $t->timestamp('conduct_signed_at')->nullable()->after('conduct_signature_file_id');
            $t->date('probation_started_at')->nullable()->after('conduct_signed_at');
            $t->timestamp('confirmed_at')->nullable()->after('probation_started_at');
            $t->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('point_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('referral_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('stage')->nullable();      // 1..4 لمراحل الإحالة، null لغيرها (استبدال، تعديل يدوي)
            $t->integer('points');                              // موجب إضافة، سالب خصم
            $t->string('reason_ar');
            $t->string('reason_en');
            $t->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('at');
            $t->timestamps();
            $t->unique(['candidate_id', 'stage']);              // مرة واحدة لكل مرحلة
            $t->index(['employee_id', 'at']);
        });

        // عناوين نماذج المباشرة كما في SRS §1.5 (للشركات الموجودة، ما لم تعدّلها الشركة) + النموذجان الجديدان
        foreach (['F-20' => ['إقرار استلام العهدة', 'إقرار إبراء الذمة الوقائي', 'Precautionary clearance declaration'], 'F-22' => ['تعهد الموظف الجديد', 'تعهد المحافظة على سرية العمل', 'Confidentiality undertaking'],
            'F-32' => ['مذكرة المباشرة', 'مذكرة الترحيب بالموظف الجديد', 'New employee welcome memo']] as $code => [$old, $ar, $en]) {
            DB::table('form_templates')->where('code', $code)->where('title_ar', $old)->update(['title_ar' => $ar, 'title_en' => $en, 'module' => 'employees']);
        }
        foreach (DB::table('companies')->pluck('id') as $company) Tenant::run($company, fn () => Forms::seed());
    }

    public function down(): void
    {
        Schema::dropIfExists('point_logs');
        Schema::table('onboardings', function (Blueprint $t) {
            $t->dropConstrainedForeignId('confirmed_by');
            $t->dropConstrainedForeignId('conduct_signature_file_id');
            $t->dropColumn(['conduct_signed_at', 'probation_started_at', 'confirmed_at']);
        });
    }
};
