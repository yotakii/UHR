<?php

namespace App\Http\Resources;

use App\Models\Candidate;
use App\Models\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * طلب المتقدم كما يراه في بوابته (M05): المسار، عرض العمل، قرار التعيين، ملخص الطلب.
 * لا يكشف ملاحظات التوظيف الداخلية ولا المطابقة ولا تقييم المقابلة.
 *
 * @mixin Candidate
 */
class MyApplicationResource extends JsonResource
{
    public static function load(Candidate $c): Candidate
    {
        return $c->load(['jobOpening.department', 'jobOpening.company', 'cv', 'offer.signature', 'appointment.approvals']);
    }

    public function toArray(Request $request): array
    {
        $job = $this->jobOpening; $o = $this->offer; $ap = $this->appointment;
        return [
            'id' => $this->id, 'company' => $job->company->card(),
            'ref' => $this->ref, 'created_at' => $this->created_at?->toIso8601String(), 'stage' => $this->stage,
            'name' => $this->name, 'name_en' => $this->name_en, 'email' => $this->email, 'mobile' => $this->mobile, 'salary' => $this->salary,
            'cv' => $this->cv ? ['name' => $this->cv->name, 'size' => $this->cv->size] : null,
            'job' => ['code' => $job->code, 'title' => ['ar' => $job->title_ar, 'en' => $job->title_en], 'dept' => ['ar' => $job->department->name_ar, 'en' => $job->department->name_en]],
            'offer' => $o ? [
                'title' => $o->title, 'contract' => $o->contract, 'currency' => $o->currency,
                'basic' => $o->basic, 'housing' => $o->housing, 'transport' => $o->transport, 'fuel' => $o->fuel, 'total' => $o->total(),
                'leave_days' => $o->leave_days, 'start_date' => $o->start_date?->toDateString(), 'issued_at' => $o->issued_at?->toIso8601String(),
                'status' => $o->status, 'responded_at' => $o->responded_at?->toIso8601String(), 'note' => $o->note,
                'signature' => self::dataUrl($o->signature),
            ] : null,
            'appointment' => $ap ? [
                'decision_no' => $ap->decision_no, 'issued_at' => $ap->issued_at?->toIso8601String(), 'complete' => $ap->isComplete(),
                'approvals' => $ap->approvals->map(fn ($a) => ['level' => $a->level, 'role' => ['ar' => $a->role_ar, 'en' => $a->role_en], 'name' => $a->approver_name, 'status' => $a->status, 'at' => $a->approved_at?->toIso8601String()])->values(),
            ] : null,
        ];
    }

    /** صورة التوقيع مضمّنة (صغيرة) — لا رابط عام لملفات التخزين */
    public static function dataUrl(?StoredFile $f): ?string
    {
        if (! $f || ! Storage::disk($f->disk)->exists($f->path)) return null;
        return 'data:'.$f->mime.';base64,'.base64_encode(Storage::disk($f->disk)->get($f->path));
    }
}
