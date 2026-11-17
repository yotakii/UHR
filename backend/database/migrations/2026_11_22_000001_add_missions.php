<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعة 20 — المهمات والسفر والمكافآت وبنود الراتب (SRS الأنظمة الأساسية، الجزء B — TMS):
 * المهمة (عامة أو سفر) بمراحلها وتقديرها وسلفتها وتسويتها، المكافآت كأنواع محكومة بالسياسة،
 * وبنود الراتب (Pay Lines) = الجسر الوحيد إلى الكشف الشهري.
 */
return new class extends Migration
{
    public function up(): void
    {
        // محافظة كل فرع (قاعدة المحافظة POL-TM-02: لا بدل سفر داخل محافظة الفرع وريفها)
        Schema::table('locations', fn (Blueprint $t) => $t->string('governorate', 3)->nullable());
        $map = ['dam' => 'DAM', 'alp' => 'ALP', 'hom' => 'HMS', 'hms' => 'HMS', 'lat' => 'LAT', 'tar' => 'TAR', 'ham' => 'HMA', 'hma' => 'HMA', 'idl' => 'IDL', 'dar' => 'DAR', 'swd' => 'SWD', 'raq' => 'RAQ', 'has' => 'HAS', 'dez' => 'DEZ', 'qun' => 'QUN', 'rdm' => 'RDM'];
        foreach (\Illuminate\Support\Facades\DB::table('locations')->get(['id', 'code']) as $l) {
            $g = $map[strtolower(substr($l->code, 0, 3))] ?? null;
            if ($g) \Illuminate\Support\Facades\DB::table('locations')->where('id', $l->id)->update(['governorate' => $g]);
        }

        Schema::create('missions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref_no', 40);
            $t->foreignId('type_id')->constrained('code_items');
            $t->foreignId('category_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->string('title'); $t->text('description')->nullable();
            $t->foreignId('requester_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('employees')->nullOnDelete();      // المسؤول
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();               // المنفّذ (فارغ للمهمة الأم)
            $t->foreignId('parent_id')->nullable()->constrained('missions')->cascadeOnDelete();
            $t->string('assignee_type', 10)->default('employee');                                // employee | team | branch
            $t->unsignedBigInteger('assignee_ref')->nullable();                                   // الإدارة أو الفرع
            $t->string('priority', 10)->default('normal');
            $t->date('starts_on')->nullable(); $t->date('due_on')->nullable();
            $t->foreignId('cost_center_id')->nullable()->constrained('code_items')->nullOnDelete();
            // draft | pending | approved | assigned | in_progress | review | done | settling | posted | closed | rejected | returned | cancelled
            $t->string('status', 16)->default('draft');
            $t->unsignedTinyInteger('progress')->default(0);
            $t->jsonb('stages')->nullable();                                                       // [{name, weight, done_at}]
            $t->jsonb('travel')->nullable();                                                       // {scope local|foreign, country, governorate, city, purpose, depart_on, return_on, transport, legs[], days, km}
            $t->jsonb('estimate')->nullable();                                                     // بطاقة التكلفة التقديرية
            $t->jsonb('advance')->nullable();                                                      // {amount, currency, status requested|paid|settled|refunded, voucher_no, paid_at}
            $t->jsonb('settlement')->nullable();                                                   // {actual, km, expenses[], report, status submitted|hr|finance|approved, …}
            $t->jsonb('log')->nullable();
            $t->jsonb('attachments')->nullable();                                                  // [file ids]
            $t->unsignedTinyInteger('rating')->nullable();
            $t->text('return_note')->nullable();
            $t->date('close_due')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->timestamp('done_at')->nullable(); $t->timestamp('closed_at')->nullable(); $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'ref_no']);
            $t->index(['company_id', 'status']);
            $t->index(['employee_id', 'status']);
        });

        Schema::create('mission_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->text('text');
            $t->jsonb('files')->nullable();
            $t->timestamps();
        });

        Schema::create('bonuses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref_no', 30);
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('type_id')->constrained('code_items');
            $t->decimal('amount', 14, 2);
            $t->char('currency', 3);
            $t->jsonb('basis')->nullable();                                                         // {method, percent|hours|rate…}
            $t->text('reason');
            $t->string('source_type', 20)->default('manual');                                      // manual | mission | review | attendance | course
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('status', 20)->default('pending');                                          // pending… (المسار) | approved | rejected | cancelled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'ref_no']);
        });

        Schema::create('pay_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('element_id')->constrained('code_items');
            $t->string('element_code', 10);
            $t->string('kind', 10);                                                                // earning | deduction
            $t->decimal('amount', 14, 2);                                                          // بعملة المصدر
            $t->char('currency', 3);
            $t->decimal('fx_rate', 16, 6)->default(1);                                              // سعر الصرف المحفوظ مع البند
            $t->decimal('amount_pay', 14, 2);                                                      // بعملة راتب الموظف
            $t->char('pay_currency', 3);
            $t->string('period', 7);                                                               // YYYY-MM
            $t->string('source_type', 20);                                                         // mission | bonus | reversal
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('ref', 40)->nullable();
            $t->string('label_ar')->nullable(); $t->string('label_en')->nullable();
            $t->string('channel', 10)->default('payroll');                                         // payroll | cash (يظهر للعلم)
            $t->string('status', 12)->default('pending');                                          // pending | posted | reversed
            $t->foreignId('month_sheet_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('reversal_of')->nullable()->constrained('pay_lines')->nullOnDelete();
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'period', 'status']);
            $t->index(['employee_id', 'period']);
        });

        \App\Support\Audit::mute(function () {
            foreach (\App\Models\Company::all() as $c) \App\Support\Tenant::run($c, function () {
                \App\Models\ApprovalChain::firstOrCreate(['type' => 'mission'], ['levels' => \App\Services\Missions\Missions::DEFAULT_CHAIN]);
                \App\Models\ApprovalChain::firstOrCreate(['type' => 'bonus'], ['levels' => [['role_ar' => 'الموارد البشرية', 'role_en' => 'Human Resources', 'role_key' => 'hr_director']]]);
                \App\Services\Codes\CodeCatalog::seedCompany();
                \App\Support\Forms::seed();
            });
        });
    }

    public function down(): void
    {
        foreach (['pay_lines', 'bonuses', 'mission_comments', 'missions'] as $t) Schema::dropIfExists($t);
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn('governorate'));
    }
};
