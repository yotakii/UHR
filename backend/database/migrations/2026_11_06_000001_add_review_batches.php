<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 11f: فرز السير وقراءتها دفعةً واحدة — دفعات القراءة ومدة كل قراءة (لتقدير الوقت المتبقي) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->jsonb('candidate_ids');                 // المرسلة للقراءة فعلاً
            $t->jsonb('counts');                        // {selected, queued, already, limit, skipped}
            $t->jsonb('filters')->nullable();
            $t->boolean('force')->default(false);
            $t->timestamps();
        });
        Schema::table('candidate_reviews', function (Blueprint $t) {
            $t->decimal('seconds', 7, 1)->nullable();   // مدة القراءة الفعلية
            $t->foreignId('batch_id')->nullable()->constrained('review_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('candidate_reviews', fn (Blueprint $t) => $t->dropConstrainedForeignId('batch_id'));
        Schema::table('candidate_reviews', fn (Blueprint $t) => $t->dropColumn('seconds'));
        Schema::dropIfExists('review_batches');
    }
};
