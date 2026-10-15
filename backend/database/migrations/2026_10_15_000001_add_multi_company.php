<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تعدد الشركات (SRS §10.3، §10.15): كل سجل عمل مرتبط بشركة، والفرادة تصير لكل شركة.
 * حسابات المتقدمين ومدير المنصة بلا شركة؛ والمتقدم الواحد له طلبات في عدة شركات (candidates.user_id).
 */
return new class extends Migration
{
    /** جداول مملوكة لشركة — company_id إلزامي */
    private const OWNED = [
        'departments', 'employees', 'job_openings', 'devices', 'candidates', 'appointments', 'referrals', 'policies', 'holidays',
        'attendance_days', 'scans', 'leave_requests', 'service_requests', 'asset_categories', 'assets', 'resignations',
        'rewards', 'redemptions', 'notices', 'announcements', 'performance_cycles', 'competencies', 'performance_reviews',
        'approval_chains', 'notification_channels', 'message_templates',
    ];

    /** company_id اختياري: حسابات المنصة والمتقدمين، إعدادات المنصة، ملفات المتقدمين، تدقيق المنصة */
    private const OPTIONAL = ['users', 'settings', 'files', 'audit_logs'];

    /** [جدول, أعمدة الفرادة القديمة] ← تصبح (company_id + الأعمدة) */
    private const RESCOPE = [
        ['departments', ['code']], ['employees', ['code']], ['employees', ['email']], ['employees', ['national_id']],
        ['job_openings', ['code']], ['candidates', ['ref']], ['appointments', ['decision_no']], ['referrals', ['code']],
        ['holidays', ['date']], ['policies', ['key', 'effective_from']], ['asset_categories', ['code']], ['assets', ['tag']],
        ['approval_chains', ['type']], ['notification_channels', ['key']], ['message_templates', ['key', 'channel']],
    ];

    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 40)->unique();               // atmata, alfarada → {slug}.النطاق و /c/{slug}
            $t->string('name_ar');
            $t->string('name_en');
            $t->string('short_ar')->nullable();
            $t->string('short_en')->nullable();
            $t->string('brand_latin', 60)->nullable();      // UNIQUENESS GROUP
            $t->text('about_ar')->nullable();
            $t->text('about_en')->nullable();
            $t->string('website')->nullable();
            $t->string('logo_path')->nullable();            // public/logos/… (شعار البطاقات والصفحة)
            $t->string('status', 12)->default('active');    // active, suspended
            $t->boolean('portal_enabled')->default(false);  // النشر على البوابة العامة
            $t->boolean('portal_review')->default(true);    // شواغرها تنتظر مراجعة المنصة قبل البوابة
            $t->boolean('is_platform')->default(false);     // Atmata — مالكة المنصة
            $t->timestamps();
        });

        foreach (self::OWNED as $table) Schema::table($table, fn (Blueprint $t) => $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete());
        foreach (self::OPTIONAL as $table) Schema::table($table, fn (Blueprint $t) => $t->foreignId('company_id')->nullable()->constrained()->nullOnDelete());

        // بيانات موجودة (بيئة تطوير قبل هذه النسخة) ← شركة افتراضية
        if (DB::table('departments')->exists() || DB::table('employees')->exists()) {
            $id = DB::table('companies')->insertGetId(['slug' => 'default', 'name_ar' => 'الشركة', 'name_en' => 'Company', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            foreach (self::OWNED as $table) DB::table($table)->update(['company_id' => $id]);
            DB::table('users')->whereNotNull('employee_id')->update(['company_id' => $id]);
            DB::table('settings')->update(['company_id' => $id]);
        }
        foreach (self::OWNED as $table) Schema::table($table, fn (Blueprint $t) => $t->foreignId('company_id')->nullable(false)->change());

        foreach (self::RESCOPE as [$table, $cols]) {
            Schema::table($table, function (Blueprint $t) use ($cols) {
                $t->dropUnique($cols);
                $t->unique(['company_id', ...$cols]);
            });
        }

        // الإعدادات: مفتاح لكل شركة، و company_id = null لإعدادات المنصة
        Schema::table('settings', function (Blueprint $t) {
            $t->dropPrimary(['key']);
        });
        Schema::table('settings', function (Blueprint $t) {
            $t->id()->first();
            $t->unique(['company_id', 'key'])->nullsNotDistinct();
        });

        // الوظيفة: مكان النشر ومراجعة المنصة (SRS §10.15) + قواعد v3 (§10.9)
        Schema::table('job_openings', function (Blueprint $t) {
            $t->string('visibility', 10)->default('company')->after('status');   // internal, company, public
            $t->string('portal_status', 10)->default('none')->after('visibility'); // none, pending, approved, rejected
            $t->string('portal_note')->nullable()->after('portal_status');
            $t->timestamp('portal_reviewed_at')->nullable()->after('portal_note');
            $t->foreignId('portal_reviewed_by')->nullable()->after('portal_reviewed_at')->constrained('users')->nullOnDelete();
            $t->date('closes_at')->nullable()->after('posted_at');
            $t->boolean('hard_to_fill')->default(false);
            $t->boolean('requires_photo')->default(false);
            $t->index(['visibility', 'portal_status', 'status']);
        });

        // المتقدم: حساب واحد ← طلبات في عدة شركات، والإقرارات والصورة
        Schema::table('candidates', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $t->foreignId('photo_file_id')->nullable()->after('cv_file_id')->constrained('files')->nullOnDelete();
            $t->timestamp('consent_at')->nullable();
            $t->boolean('keep_cv')->default(false);
            $t->string('match_band', 8)->nullable();   // normal, review, reject
        });
        DB::statement('UPDATE candidates SET user_id = users.id FROM users WHERE users.candidate_id = candidates.id');
        DB::statement('UPDATE users SET name = candidates.name FROM candidates WHERE users.candidate_id = candidates.id AND users.name IS NULL');
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('candidate_id');
            $t->string('name_en')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('name_en');
            $t->foreignId('candidate_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
        DB::statement('UPDATE users SET candidate_id = c.id FROM (SELECT DISTINCT ON (user_id) id, user_id FROM candidates WHERE user_id IS NOT NULL ORDER BY user_id, id DESC) c WHERE c.user_id = users.id');
        Schema::table('candidates', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropConstrainedForeignId('photo_file_id');
            $t->dropColumn(['consent_at', 'keep_cv', 'match_band']);
        });
        Schema::table('job_openings', function (Blueprint $t) {
            $t->dropIndex(['visibility', 'portal_status', 'status']);
            $t->dropConstrainedForeignId('portal_reviewed_by');
            $t->dropColumn(['visibility', 'portal_status', 'portal_note', 'portal_reviewed_at', 'closes_at', 'hard_to_fill', 'requires_photo']);
        });
        Schema::table('settings', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'key']);
            $t->dropColumn('id');
        });
        Schema::table('settings', fn (Blueprint $t) => $t->primary('key'));
        foreach (self::RESCOPE as [$table, $cols]) {
            Schema::table($table, function (Blueprint $t) use ($cols) {
                $t->dropUnique(['company_id', ...$cols]);
                $t->unique($cols);
            });
        }
        foreach ([...self::OWNED, ...self::OPTIONAL] as $table) Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('company_id'));
        Schema::dropIfExists('companies');
    }
};
