<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** حالات سير العمل قد تبلغ 15 حرفاً (pending_manager / pending_finance) */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['td_approvals', 'mission_invites', 'mission_amends'] as $t) Schema::table($t, fn (Blueprint $b) => $b->string('status', 20)->default('pending_hr')->change());
    }

    public function down(): void {}
};
