<?php

use App\Models\ApprovalChain;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// إذن الوصول لرواتب الآخرين (المهمة 10b): طلب ← موافقة المدير العام (سلسلة salary_access) ← صالح لساعات ثم ينتهي
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('scope', 12)->default('all');                 // all | department
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->text('reason');
            $t->string('status', 20)->default('pending_exec');      // مسار موحد + revoked
            $t->unsignedSmallInteger('hours');
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_note', 500)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'user_id', 'status']);
        });

        foreach (DB::table('companies')->pluck('id') as $company) Tenant::run($company, fn () =>
            ApprovalChain::firstOrCreate(['type' => 'salary_access'], ['levels' => [['role_ar' => 'المدير العام', 'role_en' => 'General manager', 'role_key' => 'ceo']]]));
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_grants');
        DB::table('approval_chains')->where('type', 'salary_access')->delete();
    }
};
