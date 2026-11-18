<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** وكيل جهاز الموظف UHR-Agent (D-15): الخروج بالبطاقة يقفل جلسة Windows أو يسجّل الخروج منها، مع طابور للجهاز المطفأ */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pc_agents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);                               // اسم الجهاز كما تعرفه الموارد البشرية
            $t->string('key_hash', 64)->nullable()->unique();       // sha256 لمفتاح الوكيل (يظهر مرة واحدة داخل الحزمة)
            $t->string('key_hint', 8)->nullable();
            $t->boolean('active')->default(true);
            $t->string('host', 120)->nullable();                    // اسم الحاسوب كما يبلّغ الوكيل
            $t->string('os_user', 120)->nullable();                 // مستخدم Windows الحالي
            $t->string('version', 20)->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('offline_alerted_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'employee_id']);
        });

        Schema::create('agent_commands', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('pc_agent_id')->constrained('pc_agents')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('action', 12);                               // lock | logoff | shutdown
            $t->string('reason', 12);                               // checkout | test | manual
            $t->string('source', 12)->nullable();                   // card | fingerprint | phone
            $t->unsignedSmallInteger('warn_seconds')->default(60);
            $t->unsignedSmallInteger('postpone_minutes')->default(0);
            $t->string('status', 12)->default('queued');            // queued | sent | done | failed | expired | cancelled
            $t->string('error', 255)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['pc_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
        Schema::dropIfExists('pc_agents');
    }
};
