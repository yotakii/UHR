<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الملاحظات 15d:
 * 73/78 عملة صرف واحدة للكشف وأسعار صرف مؤرخة — يُحوَّل كل صف بسعر تاريخ الصرف ويُحفظ السعر مع الكشف.
 * 75 قوالب تقارير (البنك، التأمينات، الإدارات، الاقتطاعات): بيانات حساب الموظف البنكي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->char('from', 3);
            $t->char('to', 3);
            $t->decimal('rate', 18, 6);           // 1 from = rate to
            $t->string('note', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'date', 'from', 'to']);
        });
        Schema::table('month_sheets', function (Blueprint $t) {
            $t->char('pay_currency', 3)->nullable()->after('status');   // null = كل موظف بعملة راتبه
            $t->date('pay_date')->nullable()->after('pay_currency');     // تاريخ الصرف (سعر الصرف بتاريخه)
            $t->jsonb('rates')->nullable()->after('pay_date');           // {USD: {rate, date}} السعر المستخدم لكل عملة
        });
        Schema::table('month_sheet_rows', function (Blueprint $t) {
            $t->jsonb('pay')->nullable()->after('money');                // المبالغ بعملة الصرف
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->string('bank_name', 120)->nullable();
            $t->string('bank_account', 40)->nullable();                  // رقم الحساب / IBAN
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['bank_name', 'bank_account']));
        Schema::table('month_sheet_rows', fn (Blueprint $t) => $t->dropColumn('pay'));
        Schema::table('month_sheets', fn (Blueprint $t) => $t->dropColumn(['pay_currency', 'pay_date', 'rates']));
        Schema::dropIfExists('exchange_rates');
    }
};
