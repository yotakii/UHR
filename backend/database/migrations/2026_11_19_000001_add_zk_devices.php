<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بصّامات ZKTeco عبر جسر على حاسوب الشركة (المهمة 17): إعداد الجهاز (IP/المنفذ/كلمة الاتصال)، رقم الموظف على البصّامة،
 * وسجل الحركات الواردة (مرة واحدة لكل جهاز + رقم + وقت).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $t) {
            $t->jsonb('zk')->nullable();                     // {ip, port, comm_key, machine_no, card_order, force}
            $t->timestamp('last_sync_at')->nullable();
            $t->jsonb('last_sync')->nullable();              // {ok, error, received, applied, unmatched, info}
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->string('device_pin', 20)->nullable();        // رقم الموظف على البصّامة (الافتراضي أرقام رقمه الوظيفي)
            $t->unique(['company_id', 'device_pin']);
        });
        Schema::create('device_punches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->constrained()->cascadeOnDelete();
            $t->string('pin', 20);
            $t->timestamp('punched_at');
            $t->smallInteger('verify')->nullable();          // 0 كلمة مرور · 1 بصمة · 2 بطاقة · 15 وجه …
            $t->smallInteger('state')->nullable();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('ok');         // ok | dup | unmatched | inactive
            $t->foreignId('scan_id')->nullable()->constrained('scans')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['device_id', 'pin', 'punched_at']);
            $t->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_punches');
        Schema::table('employees', function (Blueprint $t) { $t->dropUnique(['company_id', 'device_pin']); $t->dropColumn('device_pin'); });
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn(['zk', 'last_sync_at', 'last_sync']));
    }
};
