<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * العهد M10 (SRS §6 M10، D-13): حالة الأصل (available → pending_ack → assigned → available، + maintenance/retired)،
 * حالته الفنية وملاحظاته، وسجل حركة لكل أصل (إضافة، تسليم، توقيع، استرجاع، صيانة، استبعاد، استيراد).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $t) {
            $t->string('condition', 12)->default('good')->after('value');   // good, fair, damaged
            $t->date('purchased_at')->nullable()->after('condition');
            $t->text('notes')->nullable()->after('purchased_at');
            $t->timestamp('returned_at')->nullable()->after('signed_at');
        });
        Schema::create('asset_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 16);                   // created, imported, issued, signed, returned, cancelled, maintenance, available, retired, edited
            $t->string('condition', 12)->nullable();
            $t->text('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('at');
            $t->index(['asset_id', 'at']);
        });
        // سجل البداية للأصول الموجودة
        $now = now();
        DB::table('assets')->orderBy('id')->each(function ($a) use ($now) {
            DB::table('asset_movements')->insert(['company_id' => $a->company_id, 'asset_id' => $a->id, 'employee_id' => $a->employee_id,
                'action' => $a->employee_id ? ($a->status === 'assigned' ? 'signed' : 'issued') : 'created', 'at' => $a->signed_at ?? $a->assigned_at ?? $a->created_at ?? $now]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_movements');
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn(['condition', 'purchased_at', 'notes', 'returned_at']));
    }
};
