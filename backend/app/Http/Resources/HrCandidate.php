<?php

namespace App\Http\Resources;

use App\Models\Candidate;
use App\Models\Employee;
use App\Services\Recruitment\AppointmentFlow;
use App\Services\Recruitment\MatchScore;
use App\Models\User;
use Carbon\Carbon;

/** المتقدم كما يراه فريق التوظيف (M03 بطاقة اللوحة، M04 الملف الكامل) */
class HrCandidate
{
    public const WITH = ['jobOpening.department', 'offer', 'appointment.approvals', 'referrer'];

    public static function card(Candidate $c): array
    {
        $sla = $c->slaState();
        $limit = (int) (\App\Support\Settings::get('stage_sla')[$c->stage] ?? 0);
        $days = $c->stage_at ? (int) Carbon::parse($c->stage_at)->startOfDay()->diffInDays(now()->startOfDay()) : 0;
        return [
            'id' => $c->id, 'ref' => $c->ref, 'name' => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name], 'stage' => $c->stage, 'stage_at' => $c->stage_at?->toDateString(),
            'days_in_stage' => $days, 'sla' => $sla, 'sla_limit' => $limit, 'created_at' => $c->created_at?->toIso8601String(),
            'match' => $c->match_score, 'band' => $c->match_band, 'auto_flag' => $c->auto_flag, 'internal' => (bool) $c->internal_employee_id, 'referred' => (bool) $c->referrer_id,
            'referrer' => $c->referrer ? ['ar' => $c->referrer->name, 'en' => $c->referrer->name_en] : null,
            'offer' => $c->offer?->status, 'appointment' => (bool) $c->appointment,
            'job' => ['id' => $c->job_opening_id, 'code' => $c->jobOpening->code, 'title' => ['ar' => $c->jobOpening->title_ar, 'en' => $c->jobOpening->title_en]],
        ];
    }

    public static function full(Candidate $c, User $viewer): array
    {
        $c->loadMissing([...self::WITH, 'cv', 'photo', 'interview', 'offer.signature', 'internalEmployee', 'logs', 'jobOpening.location']);
        $job = $c->jobOpening; $o = $c->offer; $ap = $c->appointment;
        $m = MatchScore::compute($c, $job);
        return array_merge(self::card($c), [
            'name_ar' => $c->name, 'name_en' => $c->name_en, 'gender' => $c->gender, 'marital' => $c->marital, 'nationality' => $c->nationality, 'dob' => $c->dob?->toDateString(), 'pob' => $c->pob,
            'national_id' => $c->national_id, 'blood' => $c->blood, 'email' => $c->email, 'mobile' => $c->mobile, 'address' => $c->address, 'linkedin' => $c->linkedin,
            'education' => $c->education ?? [], 'experience' => $c->experience ?? [], 'refs' => $c->refs ?? [], 'years_exp' => $c->years_exp, 'degree_level' => $c->degree_level,
            'english' => $c->english, 'salary' => (float) $c->salary, 'availability' => $c->availability?->toDateString(), 'source' => $c->source,
            'shifts' => $c->shifts, 'travel' => $c->travel, 'public_sector' => $c->public_sector, 'conviction' => $c->conviction, 'reject_reason' => $c->reject_reason,
            'referrer' => $c->referrer ? ['code' => $c->referrer->code, 'name' => ['ar' => $c->referrer->name, 'en' => $c->referrer->name_en]] : null,
            'internal_reason' => $c->internal_reason, 'internal_employee' => $c->internalEmployee ? ['code' => $c->internalEmployee->code, 'name' => ['ar' => $c->internalEmployee->name, 'en' => $c->internalEmployee->name_en]] : null,
            'cv' => $c->cv ? ['id' => $c->cv->id, 'name' => $c->cv->name, 'size' => $c->cv->size] : null, 'photo' => $c->photo ? ['id' => $c->photo->id] : null,
            'job' => self::card($c)['job'] + ['dept' => ['ar' => $job->department->name_ar, 'en' => $job->department->name_en], 'city' => ['ar' => $job->location->name_ar, 'en' => $job->location->name_en],
                'min_years' => $job->min_years, 'degree' => $job->degree, 'salary_min' => (float) $job->salary_min, 'salary_max' => (float) $job->salary_max, 'currency' => $job->currency, 'openings' => $job->openings],
            'match_parts' => $m['parts'],
            'interview' => $c->interview ? ['ratings' => $c->interview->ratings, 'notes' => $c->interview->notes, 'recommendation' => $c->interview->recommendation,
                'interviewer' => $c->interview->interviewer, 'date' => $c->interview->date?->toDateString(), 'pct' => $c->interview->pct] : null,
            'offer' => $o ? ['title' => $o->title, 'contract' => $o->contract, 'currency' => $o->currency, 'basic' => $o->basic, 'housing' => $o->housing, 'transport' => $o->transport, 'fuel' => $o->fuel,
                'total' => $o->total(), 'leave_days' => $o->leave_days, 'start_date' => $o->start_date?->toDateString(), 'issued_at' => $o->issued_at?->toIso8601String(), 'status' => $o->status,
                'responded_at' => $o->responded_at?->toIso8601String(), 'note' => $o->note, 'signature' => MyApplicationResource::dataUrl($o->signature)] : null,
            'appointment' => $ap ? self::appointment($ap, $c, $viewer) : null,
            'logs' => $c->logs->sortByDesc('at')->values()->map(fn ($l) => ['at' => $l->at->toIso8601String(), 'ar' => $l->text_ar, 'en' => $l->text_en, 'kind' => $l->kind, 'form' => $l->form]),
        ]);
    }

    /** F-31 مع مطابقة الملاك العددي للإدارة ومن عليه الدور الآن */
    public static function appointment($ap, Candidate $c, User $viewer): array
    {
        $job = $c->jobOpening;
        $current = AppointmentFlow::current($ap);
        return [
            'id' => $ap->id, 'decision_no' => $ap->decision_no, 'issued_at' => $ap->issued_at?->toIso8601String(), 'complete' => $ap->isComplete(), 'employee_id' => $ap->employee_id,
            'rejected' => $ap->approvals->contains('status', 'rejected'),
            'approvals' => $ap->approvals->map(fn ($a) => ['id' => $a->id, 'level' => $a->level, 'role' => ['ar' => $a->role_ar, 'en' => $a->role_en], 'name' => $a->approver_name, 'status' => $a->status, 'at' => $a->approved_at?->toIso8601String()])->values(),
            'current' => $current ? ['id' => $current->id, 'role' => ['ar' => $current->role_ar, 'en' => $current->role_en], 'mine' => AppointmentFlow::canDecide($viewer, $current)] : null,
            'headcount' => ['approved' => (int) $job->department->headcount, 'current' => Employee::where('department_id', $job->department_id)->where('status', '!=', 'terminated')->count()],
            'degree_ok' => $c->degree_level >= $job->degree,
        ];
    }
}
