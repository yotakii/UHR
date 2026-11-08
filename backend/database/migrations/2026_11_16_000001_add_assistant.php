<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 13 «المساعد»: محادثة كل مستخدم (سؤاله والرد مع البطاقات والمصادر والإجراء المقترح بانتظار التأكيد) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 12);              // user | assistant
            $t->string('intent', 30)->nullable();
            $t->text('text');
            $t->jsonb('data')->nullable();       // cards, sources, suggestions, action, summary
            $t->string('lang', 2)->default('ar');
            $t->timestamps();
            $t->index(['user_id', 'id']);
            $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('assistant_messages'); }
};
