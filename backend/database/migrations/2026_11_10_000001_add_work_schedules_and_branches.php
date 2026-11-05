<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الملاحظات 15c:
 * 24/76 جداول دوام متعددة لكل شركة (ثابت أو ورديات، وقوالب موسمية بتاريخ بداية ونهاية) تُسند للموظف أو لفترة (مناوبات).
 * 80 الفروع: الشركة تضيف فروعها الخاصة، ولكل فرع منطقة زمنية تُحسب بها أوقات حضور موظفيه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $t) {
            $t->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();   // null = مدينة مشتركة على المنصة
            $t->string('timezone', 40)->default('Asia/Damascus')->after('name_en');
            $t->dropUnique(['code']);
            $t->unique(['company_id', 'code']);
        });
        Schema::create('work_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name_ar', 120);
            $t->string('name_en', 120)->nullable();
            $t->string('kind', 10)->default('fixed');      // fixed | shifts
            $t->jsonb('shifts');                            // [{key, ar, en, start, end, grace?}] دقائق من بداية اليوم
            $t->jsonb('work_days');                         // [0..6] Carbon (0 = الأحد)
            $t->date('season_from')->nullable();            // قالب موسمي: فترة سريانه المقترحة
            $t->date('season_to')->nullable();
            $t->string('color', 12)->default('brand');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('work_schedule_id')->nullable()->after('location_id')->constrained('work_schedules')->nullOnDelete();
        });
        Schema::create('shift_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('work_schedule_id')->constrained('work_schedules')->cascadeOnDelete();
            $t->string('shift_key', 20)->nullable();
            $t->date('from');
            $t->date('to')->nullable();
            $t->string('note', 300)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
        Schema::table('employees', fn (Blueprint $t) => $t->dropConstrainedForeignId('work_schedule_id'));
        Schema::dropIfExists('work_schedules');
        Schema::table('locations', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'code']);
            $t->dropConstrainedForeignId('company_id');
            $t->dropColumn('timezone');
            $t->unique(['code']);
        });
    }
};
