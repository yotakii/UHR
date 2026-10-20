<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M08 (SRS §10.10): تصحيح يوم الدوام يدوياً من الموارد البشرية بسبب إلزامي (اليوم «الناقص» بلا خروج)،
 * ومصدر الحركة؛ وأجهزة الحضور المسجّلة بمفتاح (يُعرض مرة واحدة) ونوع القراءة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_days', function (Blueprint $t) {
            $t->string('source', 10)->default('device')->after('justified');   // device, manual, import
            $t->foreignId('corrected_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $t->timestamp('corrected_at')->nullable()->after('corrected_by');
            $t->text('correction_note')->nullable()->after('corrected_at');
        });
        Schema::table('scans', function (Blueprint $t) {
            $t->string('via', 10)->default('usb');                              // usb, phone, bridge
        });
        Schema::table('devices', function (Blueprint $t) {
            $t->string('key_hint', 8)->nullable()->after('key_hash');          // آخر أحرف المفتاح للتعرّف عليه
            $t->string('last_ip', 45)->nullable()->after('last_seen_at');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', fn (Blueprint $t) => $t->dropConstrainedForeignId('corrected_by'));
        Schema::table('attendance_days', fn (Blueprint $t) => $t->dropColumn(['source', 'corrected_at', 'correction_note']));
        Schema::table('scans', fn (Blueprint $t) => $t->dropColumn('via'));
        Schema::table('devices', fn (Blueprint $t) => $t->dropConstrainedForeignId('created_by'));
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn(['key_hint', 'last_ip']));
    }
};
