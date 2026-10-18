<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\ApprovalChain;
use App\Models\Appointment;
use App\Models\Candidate;
use App\Models\User;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Signature;
use App\Support\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

/**
 * رد المتقدم على عرض العمل F-29 (respondOffer في الديمو):
 * القبول بتوقيع إلزامي يفتح قرار التعيين F-31 بسلسلة الاعتماد من الإعدادات؛ التعديل والاعتذار يُسجَّلان ويُشعَر بهما HR.
 */
class OfferResponse
{

    public function respond(Candidate $cand, string $status, ?string $signature, ?string $note): Candidate
    {
        $sigFile = $status === 'accepted' ? Signature::store($signature, 'التوقيع مطلوب لقبول العرض.', 'Your signature is required to accept the offer.') : null;
        try {
            DB::transaction(function () use ($cand, $status, $note, $sigFile) {
                $offer = $cand->offer()->lockForUpdate()->first();
                if (! $offer || $offer->status !== 'sent') {
                    throw new ApiException(L::t('لا يوجد عرض عمل بانتظار ردّك.', 'There is no job offer awaiting your response.'), 'offer_not_open');
                }
                $offer->update(['status' => $status, 'responded_at' => now(), 'signature_file_id' => $sigFile?->id, 'note' => $note ?: null]);
                $link = "cand:{$cand->id}"; $en = $cand->name_en ?: $cand->name;
                match ($status) {
                    'accepted' => $this->accept($cand, $link, $en),
                    'declined' => [$cand->log('اعتذار المرشح عن العرض'.($note ? " — {$note}" : ''), 'Candidate declined the offer'.($note ? " — {$note}" : ''), 'danger', 'F-29'),
                        Notify::hr("{$cand->name} اعتذر عن عرض العمل", "{$en} declined the offer", $link)],
                    'negotiation' => [$cand->log("طلب تعديل بنود العرض: {$note}", "Requested changes: {$note}", 'warn', 'F-29'),
                        Notify::hr("{$cand->name} طلب تعديل بنود عرض العمل", "{$en} requested offer changes", $link)],
                };
            });
        } catch (\Throwable $e) {
            if ($sigFile) { Storage::disk($sigFile->disk)->delete($sigFile->path); $sigFile->delete(); }
            throw $e;
        }
        return $cand;
    }

    private function accept(Candidate $cand, string $link, string $en): void
    {
        $cand->log('قبول عرض العمل وتوقيعه إلكترونياً', 'Offer accepted and e-signed', 'ok', 'F-29');
        $this->openAppointment($cand);
        $cand->log('فتح قرار التعيين وبدء سلسلة الاعتمادات', 'Appointment decision opened for approval', 'info', 'F-31');
        Notify::hr("{$cand->name} وقّع عرض العمل — قرار التعيين بانتظار الاعتماد", "{$en} signed the offer — appointment awaits approval", $link);
    }

    /** قرار التعيين F-31: رقم تسلسلي + مستويات الاعتماد من سلسلة «appointment» في الإعدادات (M16) */
    public function openAppointment(Candidate $cand): Appointment
    {
        $ap = Appointment::create(['candidate_id' => $cand->id, 'decision_no' => Counters::decisionNo(), 'issued_at' => now()]);
        $levels = ApprovalChain::where('type', 'appointment')->value('levels') ?? [];
        foreach (array_values($levels) as $i => $lvl) {
            $approver = $this->approver($lvl);
            $ap->approvals()->create([
                'level' => $i + 1, 'role_ar' => $lvl['role_ar'], 'role_en' => $lvl['role_en'],
                'approver_id' => $approver?->id, 'approver_name' => $approver?->displayName('ar') ?? $lvl['role_ar'], 'status' => 'pending',
            ]);
        }
        return $ap;
    }

    /** صاحب المستوى: المستخدم المحدد في السلسلة، وإلا أول حساب فعّال يحمل دور المستوى */
    private function approver(array $lvl): ?User
    {
        $q = User::with('employee')->where('active', true)->where('company_id', Tenant::id());
        if (! empty($lvl['user_id'])) return (clone $q)->find($lvl['user_id']);
        if (empty($lvl['role_key'])) return null;
        return $q->whereHas('roles', fn ($r) => $r->where('key', $lvl['role_key']))->orderBy('id')->first();
    }
}
