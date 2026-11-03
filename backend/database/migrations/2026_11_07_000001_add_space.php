<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 12a «الساحة» — تواصل اجتماعي داخلي: مجتمعات (الشركة، الإدارات، اهتمامات)، منشورات (نص، صور، استطلاع، سؤال)، تفاعلات، تعليقات، بلاغات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12);                         // company | department | interest
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name_ar', 120);
            $t->string('name_en', 120)->nullable();
            $t->string('about', 600)->nullable();
            $t->string('visibility', 10)->default('public'); // public = يقرأ الجميع وينضم من يشاء · private = الأعضاء فقط
            $t->string('color', 12)->default('brand');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'department_id']);
        });
        Schema::create('community_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('community_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 10)->default('member');      // member | admin
            $t->timestamps();
            $t->unique(['community_id', 'user_id']);
        });
        Schema::create('space_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('community_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12)->default('post');        // post | poll | question (praise | celebration في 12b)
            $t->text('body');
            $t->jsonb('images')->nullable();                // [file_id]
            $t->jsonb('poll')->nullable();                  // {options: [{id, text}], multi, closes_at}
            $t->jsonb('mentions')->nullable();              // [user_id]
            $t->foreignId('best_comment_id')->nullable();
            $t->timestamp('pinned_at')->nullable();
            $t->foreignId('pinned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedInteger('comments_count')->default(0);
            $t->unsignedInteger('reactions_count')->default(0);
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('hidden_at')->nullable();
            $t->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('hidden_reason', 300)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'community_id', 'id']);
        });
        Schema::create('space_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('space_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('space_comments')->cascadeOnDelete();
            $t->text('body');
            $t->jsonb('mentions')->nullable();
            $t->unsignedInteger('reactions_count')->default(0);
            $t->timestamp('edited_at')->nullable();
            $t->timestamp('hidden_at')->nullable();
            $t->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['post_id', 'id']);
        });
        Schema::create('space_reactions', function (Blueprint $t) {
            $t->id();
            $t->string('target', 8);                        // post | comment
            $t->unsignedBigInteger('target_id');
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 12);
            $t->timestamps();
            $t->unique(['target', 'target_id', 'user_id']);
        });
        Schema::create('space_votes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('space_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('option', 12);
            $t->timestamps();
            $t->unique(['post_id', 'user_id', 'option']);
        });
        Schema::create('space_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('target', 8);                        // post | comment
            $t->unsignedBigInteger('target_id');
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('reason', 20);                       // inappropriate | offensive | spam | private | other
            $t->string('note', 500)->nullable();
            $t->string('status', 10)->default('open');      // open | kept | removed
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['target', 'target_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['space_reports', 'space_votes', 'space_reactions', 'space_comments', 'space_posts', 'community_members', 'communities'] as $t) Schema::dropIfExists($t);
    }
};
