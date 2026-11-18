<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الدفعة 28: طلب تمديد/تقصير مهمة معتمدة بموافقة على الفرق (FR-TM-024)، وخصائص إضافية للدرجات وأنواع المهمات (FR-CS-018 / FR-TM-002) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_amends', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $t->date('depart_on'); $t->date('return_on'); $t->text('reason');
            $t->jsonb('before')->nullable(); $t->jsonb('after')->nullable();
            $t->string('status', 14)->default('pending_manager');
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        $extra = [
            'grades' => [['key' => 'travel_class', 'ar' => 'فئة بدل السفر', 'en' => 'Travel allowance class', 'type' => 'select', 'options' => ['A', 'B', 'C', 'D']],
                ['key' => 'ticket', 'ar' => 'درجة التذكرة', 'en' => 'Ticket class', 'type' => 'select', 'options' => ['economy', 'premium', 'business', 'first']]],
            'mission_types' => [['key' => 'stages', 'ar' => 'المراحل (اسم:وزن، مفصولة بفواصل)', 'en' => 'Stages (name:weight, comma separated)', 'type' => 'text'],
                ['key' => 'proof', 'ar' => 'الإثبات المطلوب', 'en' => 'Required proof', 'type' => 'text']],
        ];
        foreach ($extra as $key => $add) foreach (DB::table('code_lists')->where('key', $key)->get() as $l) {
            $schema = json_decode($l->attrs_schema ?? '[]', true) ?: [];
            foreach ($add as $a) if (! collect($schema)->firstWhere('key', $a['key'])) $schema[] = $a;
            DB::table('code_lists')->where('id', $l->id)->update(['attrs_schema' => json_encode($schema, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void { Schema::dropIfExists('mission_amends'); }
};
