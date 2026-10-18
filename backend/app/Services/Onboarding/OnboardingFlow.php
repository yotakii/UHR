<?php

namespace App\Services\Onboarding;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\Onboarding;
use App\Models\User;
use App\Services\Referrals\ReferralPoints;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Signature;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * المباشرة M06 (toggleCheck وsendMemo وassignAsset وsignPledge وackAsset في الديمو):
 * F-19 بإنجازاته التلقائية، مذكرة الترحيب F-32، تسليم العهدة، التعهدات F-22/F-20 وإقرار ميثاق السلوك،
 * الانتقال لفترة التجربة عند 14 بنداً، وتثبيت التعيين (البند 28) مع نقاط الإحالة للمرحلة 4.
 */
class OnboardingFlow
{
    public function __construct(private ReferralPoints $points) {}

    public function toggle(Employee $e, int $i, User $by): Onboarding
    {
        $ob = $this->open($e);
        if (! isset(F19::ITEMS[$i])) throw ApiException::field('index', L::t('بند غير موجود.', 'Unknown item.'), 'unknown_item');
        if (isset(F19::AUTO[$i])) throw new ApiException(L::t('هذا البند يُنجز تلقائياً من مصدره (التوقيع أو التعميم أو التثبيت).', 'This item completes automatically from its source (signature, memo or confirmation).'), 'auto_item');
        DB::transaction(function () use ($e, $ob, $i, $by) {
            $list = $ob->checklist; $list[$i] = ! $list[$i];
            $ob->update(['checklist' => $list]);
            [$ar, $en] = F19::ITEMS[$i]; $n = $i + 1;
            $e->log(($list[$i] ? 'إنجاز' : 'إلغاء إنجاز')." البند {$n} من F-19: {$ar} ({$by->displayName('ar')})", ($list[$i] ? 'Done' : 'Undone')." F-19 item {$n}: {$en} ({$by->displayName('en')})", $list[$i] ? 'ok' : 'warn', 'F-19');
            $this->advance($e, $ob);
        });
        return $ob->refresh();
    }

    /** F-32: تعميم مذكرة الترحيب على موظفي الشركة (إشعار) — ينجز البند 5 */
    public function memo(Employee $e, User $by): Onboarding
    {
        $ob = $this->open($e);
        DB::transaction(function () use ($e, $ob, $by) {
            $resend = (bool) $ob->memo_sent_at;
            $ob->update(['memo_sent_at' => now(), 'checklist' => $this->mark($ob->checklist, 4)]);
            $e->log(($resend ? 'إعادة تعميم' : 'تعميم').' مذكرة الترحيب على الأقسام', 'Welcome memo '.($resend ? 're-circulated' : 'circulated'), 'info', 'F-32');
            $e->loadMissing('department');
            $users = User::where('company_id', Tenant::id())->where('active', true)->whereNotNull('employee_id')->where('employee_id', '!=', $e->id)->pluck('id');
            foreach ($users as $u) Notify::user($u, "مذكرة ترحيب: انضم {$e->name} إلى {$e->department->name_ar} بوظيفة {$e->title_ar}", "Welcome: {$e->name_en} joined {$e->department->name_en} as {$e->title_en}");
            $this->advance($e, $ob);
        });
        return $ob->refresh();
    }

    /** تسليم أصل من المستودع — بانتظار توقيع الموظف من «وثائق للتوقيع» */
    public function issueAsset(Employee $e, int $assetId, User $by): Asset
    {
        $this->live($e);
        return DB::transaction(function () use ($e, $assetId, $by) {
            $a = Asset::whereKey($assetId)->lockForUpdate()->first();
            if (! $a || $a->status !== 'available') throw ApiException::field('asset_id', L::t('هذا الأصل غير متاح في المستودع.', 'This asset is not available in stock.'), 'asset_unavailable');
            $a->update(['employee_id' => $e->id, 'status' => 'pending_ack', 'assigned_at' => now(), 'signed_at' => null, 'signature_file_id' => null]);
            $e->log("تسليم عهدة: {$a->name_ar} ({$a->tag}) — بانتظار توقيع الاستلام ({$by->displayName('ar')})", "Custody issued: {$a->name_en} ({$a->tag}) — awaiting signature ({$by->displayName('en')})");
            Notify::user($e->user()->value('id'), "سُلّمت لك عهدة: {$a->name_ar} — وقّع سند الاستلام", "Asset issued to you: {$a->name_en} — please sign the receipt", 'docs');
            $this->syncCustody($e);
            return $a;
        });
    }

    /** إرجاع أصل لم يُوقَّع استلامه بعد (خطأ في التسليم) — العهد الموقّعة تُعاد من وحدة العهد */
    public function returnAsset(Employee $e, int $assetId, User $by): void
    {
        DB::transaction(function () use ($e, $assetId, $by) {
            $a = Asset::whereKey($assetId)->where('employee_id', $e->id)->lockForUpdate()->first();
            if (! $a) throw new ApiException(L::t('الأصل غير مسلّم لهذا الموظف.', 'This asset is not issued to this employee.'), 'not_found', 404);
            if ($a->status !== 'pending_ack') throw new ApiException(L::t('وقّع الموظف استلام هذا الأصل — تتم إعادته من وحدة العهد.', 'The employee signed for this asset — return it from the custody module.'), 'asset_signed');
            $a->update(['employee_id' => null, 'status' => 'available', 'assigned_at' => null]);
            $e->log("إلغاء تسليم عهدة: {$a->name_ar} ({$a->tag}) ({$by->displayName('ar')})", "Custody issue cancelled: {$a->name_en} ({$a->tag}) ({$by->displayName('en')})", 'warn');
            $this->syncCustody($e);
        });
    }

    /**
     * توقيع الموظف من حسابه: f22 تعهد السرية، f20 إبراء الذمة الوقائي (مع اسم الأم)، conduct ميثاق السلوك، asset سند استلام عهدة.
     * لا توقيع مرتين لنفس الوثيقة.
     */
    public function sign(Employee $e, string $doc, ?string $signature, array $extra = []): void
    {
        if ($doc === 'asset') { $this->ackAsset($e, (int) ($extra['asset_id'] ?? 0), $signature); return; }
        $ob = $e->onboarding()->first();
        if (! $ob || $e->status === 'terminated') throw new ApiException(L::t('لا توجد تعهدات بانتظار توقيعك.', 'No undertakings are waiting for your signature.'), 'no_onboarding', 404);
        $col = ['f22' => 'f22', 'f20' => 'f20', 'conduct' => 'conduct'][$doc] ?? throw ApiException::field('doc', L::t('وثيقة غير معروفة.', 'Unknown document.'), 'unknown_doc');
        if ($ob->{"{$col}_signed_at"}) throw new ApiException(L::t('وقّعت هذه الوثيقة سابقاً.', 'You already signed this document.'), 'already_signed');
        $mother = trim((string) ($extra['mother_name'] ?? ''));
        if ($doc === 'f20' && mb_strlen($mother) < 3) throw ApiException::field('mother_name', L::t('اكتب اسم الأم الكامل.', "Write your mother's full name."), 'mother_required');
        $file = Signature::store($signature);
        DB::transaction(function () use ($e, $ob, $doc, $col, $file, $mother) {
            $set = ["{$col}_signature_file_id" => $file->id, "{$col}_signed_at" => now()];
            if ($doc === 'f20') $set['f20_mother_name'] = $mother;
            if ($doc === 'f22') $set['checklist'] = $this->mark($ob->checklist, 10);
            if ($doc === 'f20') $set['checklist'] = $this->mark($ob->checklist, 12);
            $ob->update($set);
            [$ar, $en, $form] = ['f22' => ['توقيع تعهد المحافظة على سرية العمل', 'Confidentiality undertaking signed', 'F-22'],
                'f20' => ['توقيع إقرار إبراء الذمة الوقائي', 'Precautionary clearance signed', 'F-20'],
                'conduct' => ['توقيع إقرار ميثاق الأخلاق والسلوك المهني', 'Code of conduct acknowledgment signed', null]][$doc];
            $e->log($ar, $en, 'ok', $form);
            Notify::hr("{$e->name}: {$ar}", "{$e->name_en}: {$en}", "onboard:{$e->id}");
            $this->advance($e, $ob->refresh());
        });
    }

    /** تثبيت التعيين بعد التجربة (البند 28): الحالة active + نقاط الإحالة للمرحلة 4 */
    public function confirm(Employee $e, User $by, ?string $note): Onboarding
    {
        $ob = $this->open($e);
        if ($e->status !== 'probation') throw new ApiException(L::t('التثبيت يكون في فترة التجربة فقط.', 'Confirmation is only possible during probation.'), 'not_in_probation');
        DB::transaction(function () use ($e, $ob, $by, $note) {
            $ob->update(['checklist' => $this->mark($ob->checklist, 27), 'confirmed_at' => now(), 'confirmed_by' => $by->id]);
            $e->update(['status' => 'active']);
            $n = $note ? ' — '.trim($note) : '';
            $e->log("تثبيت التعيين بعد اجتياز فترة التجربة ({$by->displayName('ar')}){$n}", "Appointment confirmed after probation ({$by->displayName('en')}){$n}", 'ok', 'F-19');
            Notify::user($e->user()->value('id'), 'مبارك! ثُبّت تعيينك بعد اجتياز فترة التجربة', 'Congratulations! Your appointment is confirmed after probation');
            if ($c = $e->candidate()->first()) $this->points->award($c, 4);
            Audit::record('employee.confirmed', $e, null, ['note' => $note]);
        });
        return $ob->refresh();
    }

    /** نهاية فترة التجربة المتوقعة: بدايتها + مدة السياسة */
    public static function probationEnd(Employee $e, ?Onboarding $ob): ?string
    {
        $start = $ob?->probation_started_at ?? ($e->status === 'probation' ? $e->hire_date : null);
        return $start ? Carbon::parse($start)->addDays((int) Settings::policy('probation_days', $start))->toDateString() : null;
    }

    private function ackAsset(Employee $e, int $assetId, ?string $signature): void
    {
        $a = Asset::where('employee_id', $e->id)->find($assetId);
        if (! $a) throw new ApiException(L::t('هذه العهدة غير مسلّمة لك.', 'This asset is not issued to you.'), 'not_found', 404);
        if ($a->status !== 'pending_ack') throw new ApiException(L::t('وقّعت استلام هذه العهدة سابقاً.', 'You already signed for this asset.'), 'already_signed');
        $file = Signature::store($signature);
        DB::transaction(function () use ($e, $a, $file) {
            $a->update(['status' => 'assigned', 'signed_at' => now(), 'signature_file_id' => $file->id]);
            $e->log("توقيع استلام العهدة: {$a->name_ar} ({$a->tag})", "Custody receipt signed: {$a->name_en} ({$a->tag})", 'ok');
            Notify::hr("{$e->name} وقّع استلام {$a->name_ar}", "{$e->name_en} signed for {$a->name_en}", $e->onboarding()->exists() ? "onboard:{$e->id}" : 'custody');
            $this->syncCustody($e);
        });
    }

    /** البند 23: كل عهد الموظف موقّعة (وله عهدة واحدة على الأقل) */
    private function syncCustody(Employee $e): void
    {
        $ob = $e->onboarding()->first();
        if (! $ob || $ob->confirmed_at) return;
        $assets = Asset::where('employee_id', $e->id)->pluck('status');
        $done = $assets->isNotEmpty() && $assets->every(fn ($s) => $s === 'assigned');
        if (($ob->checklist[22] ?? false) !== $done) { $list = $ob->checklist; $list[22] = $done; $ob->update(['checklist' => $list]); }
        $this->advance($e, $ob->refresh());
    }

    /** ≥ 14 بنداً والحالة «قيد المباشرة» ← فترة التجربة */
    private function advance(Employee $e, Onboarding $ob): void
    {
        if ($e->status !== 'onboarding' || $ob->doneCount() < F19::PROBATION_AT) return;
        $days = (int) Settings::policy('probation_days');
        $e->update(['status' => 'probation']);
        $ob->update(['probation_started_at' => now()->toDateString()]);
        $e->log("انتهاء إجراءات المباشرة — بدء فترة التجربة ({$days} يوماً)", "Onboarding done — probation started ({$days} days)", 'ok', 'F-19');
        Notify::user($e->user()->value('id'), "اكتملت إجراءات مباشرتك — بدأت فترة التجربة ({$days} يوماً)", "Your onboarding is complete — probation started ({$days} days)");
    }

    private function mark(array $list, int $i): array { $list[$i] = true; return $list; }

    /** سجل مباشرة مفتوح (لم يُثبَّت الموظف ولم تنتهِ خدمته) */
    private function open(Employee $e): Onboarding
    {
        $this->live($e);
        $ob = $e->onboarding()->first();
        if (! $ob) throw new ApiException(L::t('لا يوجد سجل مباشرة لهذا الموظف.', 'This employee has no onboarding record.'), 'no_onboarding', 404);
        if ($ob->confirmed_at) throw new ApiException(L::t('ثُبّت تعيين الموظف — أُغلق سجل المباشرة.', 'The employee is confirmed — onboarding is closed.'), 'onboarding_closed');
        return $ob;
    }

    private function live(Employee $e): void
    {
        if (in_array($e->status, ['terminated', 'resigning'], true)) throw new ApiException(L::t('خدمة الموظف منتهية أو قيد الإنهاء.', "The employee's service is ending or ended."), 'employee_inactive');
    }
}
