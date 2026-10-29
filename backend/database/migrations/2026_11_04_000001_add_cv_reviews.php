<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قراءة السيرة الآلية (نموذج محلي مجاني على الخادم): لكل طلب توظيف تقييم استرشادي — الدرجة، مدى الملاءمة، ملخص، نقاط القوة والنواقص.
 * لا يغيّر مرحلة الطلب ولا يرفض أحداً؛ القرار للموارد البشرية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('status', 10)->default('pending'); // pending | running | done | failed
            $t->unsignedTinyInteger('score')->nullable();
            $t->string('fit', 10)->nullable(); // strong | good | partial | weak
            $t->text('summary')->nullable();
            $t->jsonb('strengths')->nullable();
            $t->jsonb('gaps')->nullable();
            $t->string('model', 60)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_reviews');
    }
};
