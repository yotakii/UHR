<?php

use App\Models\ApprovalChain;
use App\Support\Forms;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * كشف الدوام والمستحقات الشهري (D-01): مسودة ← محتسب ← معتمد ← مغلق، بصف لكل موظف (أيام، دقائق، مبالغ تقديرية)،
 * والسلف تُخصم عند الإغلاق. + طلبات الخدمات بالمسار الموحد والشهادات برقم تسلسلي وبيانات الجهة (D-16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('month_sheets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->char('month', 7);                                // 2026-10
            $t->string('status', 12)->default('draft');          // draft, calculated, approved, closed
            $t->jsonb('totals')->nullable();
            $t->text('note')->nullable();
            $t->timestamp('calculated_at')->nullable();
            $t->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'month']);
        });

        Schema::create('month_sheet_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('month_sheet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->jsonb('data');                                   // أيام ودقائق الدوام
            $t->jsonb('money');                                  // المبالغ التقديرية
            $t->decimal('salary', 12, 2)->default(0);
            $t->char('currency', 3)->default('USD');
            $t->timestamps();
            $t->unique(['month_sheet_id', 'employee_id']);
        });

        Schema::create('advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 12, 2);
            $t->char('currency', 3)->default('USD');
            $t->char('month', 7);                                // شهر الخصم
            $t->string('status', 12)->default('open');           // open, deducted, cancelled
            $t->foreignId('month_sheet_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'month']);
        });

        Schema::table('service_requests', function (Blueprint $t) {
            $t->string('status', 16)->default('pending_hr')->change();
            $t->text('note')->nullable()->change();
            $t->foreignId('requested_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $t->jsonb('addressee')->nullable()->after('amount');        // الجهة: name, cr_no, email, phone
            $t->boolean('notify_consent')->default(false)->after('addressee');
            $t->jsonb('data')->nullable()->after('notify_consent');     // تحديث البيانات
            $t->string('ref_no', 40)->nullable()->after('data');
            $t->timestamp('issued_at')->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
        });
        DB::table('service_requests')->where('status', 'pending')->update(['status' => 'pending_hr']);

        foreach (DB::table('companies')->pluck('id') as $company) Tenant::run($company, function () {
            Forms::seed();
            $hr = ['role_ar' => 'الموارد البشرية', 'role_en' => 'Human Resources', 'role_key' => 'hr_director'];
            ApprovalChain::firstOrCreate(['type' => 'service'], ['levels' => [$hr]]);
            ApprovalChain::firstOrCreate(['type' => 'advance'], ['levels' => [$hr]]);
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requested_by');
            $t->dropConstrainedForeignId('issued_by');
            $t->dropColumn(['addressee', 'notify_consent', 'data', 'ref_no', 'issued_at', 'decided_at']);
        });
        Schema::dropIfExists('advances');
        Schema::dropIfExists('month_sheet_rows');
        Schema::dropIfExists('month_sheets');
    }
};
