<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعة 21 — NFC وتعدد الفروع (SRS الأنظمة الأساسية، الجزء C):
 * الجهاز يعرّف الشركة والفرع، والبطاقة تُبحث داخل شركة الجهاز فقط، وصلاحية الفرع (أساسي/مصرّح/مؤقت/مهمة)،
 * وكل قراءة تُحفظ خاماً مع القرار وسببه.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $t) {
            $t->string('code', 30)->nullable();                     // DV-DAM01-02 من قالب «الأجهزة»
            $t->string('serial', 60)->nullable();
            $t->string('direction', 5)->default('both');            // in | out | both
            $t->string('site', 120)->nullable();                    // موقع العمل داخل الفرع (البوابة الرئيسية…)
            $t->string('status', 10)->default('active');            // pending | active | disabled
            $t->string('activation_hash', 64)->nullable();
            $t->timestamp('activation_expires_at')->nullable();
            $t->text('secret')->nullable();                          // مشفّر — لتوقيع الرسائل HMAC
            $t->boolean('require_signature')->default(false);
            $t->string('lan_ip', 45)->nullable();                   // العنوان الداخلي للعلم فقط
            $t->integer('clock_drift')->nullable();                 // ثوانٍ: ساعة الجهاز − ساعة الخادم
            $t->timestamp('offline_alerted_at')->nullable();
            $t->jsonb('gateway')->nullable();                       // {version, buffered}
            $t->unique(['company_id', 'code']);
        });
        DB::table('devices')->update(['status' => DB::raw("case when active then 'active' else 'disabled' end")]);

        Schema::table('locations', function (Blueprint $t) {
            $t->decimal('lat', 10, 7)->nullable(); $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedInteger('radius')->default(0);             // متر — 0 = دون تحقق من الموقع
            $t->string('scan_token', 40)->nullable()->unique();     // ملصق الفرع (NFC أو QR)
            $t->jsonb('allowed_ips')->nullable();                   // طبقة تحقق إضافية اختيارية
        });

        // البطاقة نفسها قد تُسجَّل في شركتين (قابضة) — التفرّد داخل الشركة فقط
        Schema::table('employees', fn (Blueprint $t) => $t->dropUnique(['nfc_uid']));
        Schema::table('employees', fn (Blueprint $t) => $t->index(['company_id', 'nfc_uid']));

        Schema::create('credentials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12);                                 // nfc | fingerprint | face | phone
            $t->string('identifier', 60);                           // nfc بصيغة 04:A1:…، والبصمة رقم المستخدم في البصّامة
            $t->string('norm', 60);                                 // بلا فواصل وبأحرف كبيرة للمطابقة
            $t->string('label', 120)->nullable();
            $t->string('status', 10)->default('active');            // active | lost | suspended | revoked | replaced
            $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable();
            $t->string('reason', 255)->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->unsignedInteger('alerts')->default(0);
            $t->timestamps();
            $t->index(['company_id', 'type', 'norm']);
            $t->index(['employee_id', 'status']);
        });

        Schema::create('branch_access', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12);                                 // permanent | temporary | secondment
            $t->date('from')->nullable(); $t->date('to')->nullable();
            $t->string('note', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'location_id']);
        });

        Schema::create('nf_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $t->string('identifier', 60)->nullable();
            $t->string('via', 10)->default('nfc');                  // nfc | phone | sticker | zk
            $t->timestamp('occurred_at');                           // بالتوقيت العالمي
            $t->timestamp('received_at');
            $t->unsignedBigInteger('seq')->nullable();
            $t->string('decision', 10);                             // accepted | flagged | rejected | duplicate
            $t->string('reason', 20)->nullable();                   // home | permanent | temporary | secondment | mission | any | unknown_card | lost_card | suspended | inactive | expired | off_branch | duplicate
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('mission_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('scan_id')->nullable()->constrained()->nullOnDelete();
            $t->string('direction', 3)->nullable();
            $t->string('review', 10)->nullable();                   // pending | accepted | rejected (للمعلَّم خارج الفرع)
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->string('review_note', 255)->nullable();
            $t->index(['company_id', 'occurred_at']);
            $t->index(['device_id', 'occurred_at']);
            $t->index(['company_id', 'decision']);
            $t->unique(['device_id', 'seq']);
        });

        // سجل الاعتمادات من البطاقات المربوطة سابقاً وأرقام البصّامات
        foreach (DB::table('employees')->whereNotNull('nfc_uid')->get(['id', 'company_id', 'nfc_uid']) as $e) {
            DB::table('credentials')->insert(['company_id' => $e->company_id, 'employee_id' => $e->id, 'type' => 'nfc', 'identifier' => $e->nfc_uid,
                'norm' => strtoupper(preg_replace('/[^0-9a-zA-Z]/', '', $e->nfc_uid)), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        if (Schema::hasColumn('employees', 'device_pin')) foreach (DB::table('employees')->whereNotNull('device_pin')->get(['id', 'company_id', 'device_pin']) as $e) {
            DB::table('credentials')->insert(['company_id' => $e->company_id, 'employee_id' => $e->id, 'type' => 'fingerprint', 'identifier' => $e->device_pin,
                'norm' => strtoupper($e->device_pin), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        // الحالة «مهمة» موجودة في الحضور، والحضور خارج الفرع يُعلَّم
        Schema::table('attendance_days', fn (Blueprint $t) => $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete());
        Schema::table('attendance_days', fn (Blueprint $t) => $t->foreignId('mission_id')->nullable()->constrained()->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('attendance_days', fn (Blueprint $t) => $t->dropConstrainedForeignId('mission_id'));
        Schema::table('attendance_days', fn (Blueprint $t) => $t->dropConstrainedForeignId('location_id'));
        Schema::dropIfExists('nf_events');
        Schema::dropIfExists('branch_access');
        Schema::dropIfExists('credentials');
        Schema::table('employees', fn (Blueprint $t) => $t->dropIndex(['company_id', 'nfc_uid']));
        Schema::table('employees', fn (Blueprint $t) => $t->unique('nfc_uid'));
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn(['lat', 'lng', 'radius', 'scan_token', 'allowed_ips']));
        Schema::table('devices', function (Blueprint $t) {
            $t->dropUnique(['company_id', 'code']);
            $t->dropColumn(['code', 'serial', 'direction', 'site', 'status', 'activation_hash', 'activation_expires_at', 'secret', 'require_signature', 'lan_ip', 'clock_drift', 'offline_alerted_at', 'gateway']);
        });
    }
};
