<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Support\Audit;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * متابعة طلب التوظيف دون تسجيل دخول: رقم المتابعة + البريد الذي قُدّم به الطلب (الاثنان معاً).
 * يعرض مرحلة عامة مبسطة فقط — لا ملاحظات ولا مطابقة ولا مبالغ. رد موحّد عند عدم التطابق، ومحدود المحاولات.
 */
class TrackController extends Controller
{
    /** المرحلة كما يراها المتقدم */
    public const PUBLIC_STAGES = ['received', 'review', 'interview', 'offer', 'hired', 'closed'];

    public function track(Request $request): JsonResponse
    {
        $d = $request->validate(['ref' => ['required', 'string', 'max:40'], 'email' => ['required', 'email', 'max:190']], [
            'ref.required' => L::t('أدخل رقم المتابعة.', 'Enter the reference number.'), 'email.required' => L::t('أدخل البريد الإلكتروني.', 'Enter your email.'),
            'email.email' => L::t('صيغة البريد غير صحيحة.', 'Invalid email format.')]);
        $ref = strtoupper(preg_replace('/\s+/', '', $d['ref']));
        $c = Candidate::allCompanies()->with(['jobOpening' => fn ($q) => $q->withoutGlobalScopes(), 'jobOpening.company', 'offer' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereRaw('upper(ref) = ?', [$ref])->whereRaw('lower(email) = ?', [strtolower(trim($d['email']))])->whereNull('erased_at')->first();
        if (! $c || ! $c->jobOpening) {
            throw new ApiException(L::t('لم نجد طلباً بهذا الرقم وهذا البريد. تأكد منهما كما وردا في رسالة التأكيد.', 'No application matches this reference and email. Check them against the confirmation message.'), 'not_found', 404);
        }
        $job = $c->jobOpening;
        \App\Support\Tenant::run($c->company_id, fn () => Audit::record('application.tracked', null, null, ['ref' => $c->ref]));
        return response()->json(['data' => [
            'ref' => $c->ref, 'status' => self::status($c), 'job' => ['ar' => $job->title_ar, 'en' => $job->title_en, 'code' => $job->code],
            'company' => $job->company->card(), 'submitted_at' => $c->created_at?->toIso8601String(), 'updated_at' => $c->updated_at?->toIso8601String(),
            'has_account' => (bool) $c->user_id,
        ]]);
    }

    public static function status(Candidate $c): string
    {
        $offerVisible = $c->offer && ! in_array($c->offer->status, ['pending_approval', 'returned'], true);
        return match ($c->stage) {
            'new' => 'received',
            'screening' => 'review',
            'interview', 'technical' => 'interview',
            'offer' => $offerVisible ? 'offer' : 'interview',
            'hired' => 'hired',
            'rejected' => 'closed',
            default => 'review',
        };
    }
}
