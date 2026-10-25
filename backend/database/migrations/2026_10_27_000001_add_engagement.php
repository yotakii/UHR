<?php

use App\Models\ApprovalChain;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M12 (SRS §10.9، §10.12): الاستبدال بقرار وملاحظة، الطلب الداخلي F-27 بالمسار الموحد (المدير المباشر فقط — D-22)،
 * والإعلانات (D-06): عنوان، صورة أو مرفق، فئة مستهدفة، نشر وانتهاء، واعتماد اختياري.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $t) {
            $t->string('title_ar', 190)->nullable();
            $t->string('title_en', 190)->nullable();
            $t->foreignId('image_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->foreignId('attachment_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->string('audience', 12)->default('all');                     // all, department
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('published');                  // draft, pending, published, expired
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('notified_at')->nullable();
        });
        DB::table('announcements')->update(['notified_at' => DB::raw('published_at')]);   // إعلانات الديمو لا تُرسل إشعارات من جديد

        Schema::table('redemptions', function (Blueprint $t) {
            $t->text('note')->nullable();
            $t->timestamp('decided_at')->nullable();
        });

        Schema::create('internal_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('job_opening_id')->constrained()->cascadeOnDelete();
            $t->text('reason');
            $t->string('status', 16)->default('pending_manager');
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'job_opening_id']);
        });

        foreach (DB::table('companies')->pluck('id') as $company) Tenant::run($company, function () {
            ApprovalChain::firstOrCreate(['type' => 'internal'], ['levels' => [['role_ar' => 'المدير المباشر', 'role_en' => 'Direct manager', 'role_key' => 'manager']]]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_applications');
        Schema::table('redemptions', fn (Blueprint $t) => $t->dropColumn(['note', 'decided_at']));
        Schema::table('announcements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('image_file_id');
            $t->dropConstrainedForeignId('attachment_file_id');
            $t->dropConstrainedForeignId('department_id');
            $t->dropConstrainedForeignId('approved_by');
            $t->dropColumn(['title_ar', 'title_en', 'audience', 'status', 'expires_at', 'approved_at', 'notified_at']);
        });
    }
};
