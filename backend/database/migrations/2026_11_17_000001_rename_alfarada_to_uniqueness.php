<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * المعرّف اللاتيني لشركة «الفرادة» أصبح uniqueness بدل alfarada (طلب المستخدم 8 تشرين الثاني):
 * رابط الشركة /c/uniqueness، والبريد @uniqueness.sy. الاسم العربي للشركة لا يتغير.
 * يُطبَّق على البيانات الموجودة (نسخ التجربة) مرة واحدة، ولا يفعل شيئاً إن لم توجد الشركة أو وُجد المعرّف الجديد.
 */
return new class extends Migration
{
    public function up(): void
    {
        $c = DB::table('companies')->where('slug', 'alfarada')->first();
        if (! $c || DB::table('companies')->where('slug', 'uniqueness')->exists()) return;
        $r = fn (string $col) => DB::raw("replace({$col}, 'alfarada', 'uniqueness')");
        DB::transaction(function () use ($c, $r) {
            DB::table('companies')->where('id', $c->id)->update(['slug' => 'uniqueness', 'domain' => $r('domain')]);
            // البريد: كل حساب وموظف ببريد الشركة القديم (أسماء الدخول)، وطلبات التوظيف وسجل البريد
            foreach (['users', 'employees', 'candidates', 'password_reset_tokens'] as $t) {
                $q = DB::table($t)->where('email', 'like', '%@alfarada.sy');
                // تجنب التعارض إن وُجد البريد الجديد مسبقاً
                $q->whereRaw("replace(email, '@alfarada.sy', '@uniqueness.sy') not in (select email from {$t} where email is not null)")
                    ->update(['email' => DB::raw("replace(email, '@alfarada.sy', '@uniqueness.sy')")]);
            }
            DB::table('mail_logs')->where('to', 'like', '%alfarada%')->update(['to' => $r('"to"')]);
            DB::table('notices')->where('link', 'like', '%alfarada%')->update(['link' => $r('link')]);
            // إعدادات الشركة (النطاق، رابط الوظائف، نطاقات البريد، القنوات) — نص JSON
            DB::table('settings')->whereRaw("value::text like '%alfarada%'")->update(['value' => DB::raw("replace(value::text, 'alfarada', 'uniqueness')::jsonb")]);
        });
    }

    public function down(): void {}
};
