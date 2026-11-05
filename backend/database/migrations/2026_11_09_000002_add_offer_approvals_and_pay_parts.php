<?php

use App\Models\ApprovalChain;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الملاحظات 15b:
 * 56/70 موافقة على عرض عمل يتجاوز ميزانية الشاغر (سلسلة offer_budget ← المدير العام) — العرض لا يُرسل قبلها.
 * 59 تفاصيل راتب الموظف: الأساسي والبدلات ونوع العقد (كما في عرض العمل)، والراتب = مجموعها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $t->decimal('total', 14, 2);
            $t->decimal('budget', 14, 2);
            $t->string('currency', 3);
            $t->string('job_currency', 3);
            $t->text('reason');
            $t->string('status', 24)->default('pending');   // pending_* | approved | rejected | cancelled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });
        Schema::table('offers', fn (Blueprint $t) => $t->string('status', 20)->change());   // + pending_approval، returned
        Schema::table('employees', function (Blueprint $t) {
            $t->string('contract', 12)->nullable()->after('currency');   // fixed | open | project
            $t->jsonb('pay')->nullable()->after('contract');              // {basic, housing, transport, fuel}
        });
        foreach (DB::table('companies')->pluck('id') as $company) Tenant::run($company, fn () =>
            ApprovalChain::firstOrCreate(['type' => 'offer_budget'], ['levels' => [['role_ar' => 'المدير العام', 'role_en' => 'General manager', 'role_key' => 'ceo']]]));
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['contract', 'pay']));
        Schema::dropIfExists('offer_approvals');
        DB::table('approval_chains')->where('type', 'offer_budget')->delete();
    }
};
