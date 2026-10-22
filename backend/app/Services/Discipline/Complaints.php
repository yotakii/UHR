<?php

namespace App\Services\Discipline;

use App\Exceptions\ApiException;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;

/**
 * شكاوى الموظفين HR.01-01-F-02 (SRS §10.7): من بوابة الموظف للموارد البشرية مباشرة — سرية (لا يراها المدير، والإشعار بلا تفاصيل).
 * الحالات: مستلمة ← قيد الدراسة ← مغلقة برد مكتوب.
 */
class Complaints
{
    public function submit(Employee $e, array $d): Complaint
    {
        if (Complaint::where('employee_id', $e->id)->where('created_at', '>=', now()->subDay())->count() >= 3) {
            throw new ApiException(L::t('قدّمت عدة شكاوى اليوم — تابع ردود الموارد البشرية أولاً.', 'You have filed several complaints today — wait for HR’s replies first.'), 'too_many', 429);
        }
        $y = now()->year;
        $c = Complaint::create(['ref_no' => 'CMP-'.$y.'-'.str_pad((string) Counters::next("complaint-{$y}"), 4, '0', STR_PAD_LEFT), 'employee_id' => $e->id,
            'subject' => trim($d['subject']), 'body' => trim($d['body']), 'against' => trim((string) ($d['against'] ?? '')) ?: null, 'status' => 'received']);
        Notify::hr("شكوى جديدة {$c->ref_no} وصلت للموارد البشرية", "New complaint {$c->ref_no} received", 'complaint:'.$c->id);
        return $c;
    }

    public function review(Complaint $c, User $u): Complaint
    {
        if ($c->status !== 'received') return $c;
        $c->update(['status' => 'reviewing']);
        Notify::user(User::where('employee_id', $c->employee_id)->value('id'), "شكواك {$c->ref_no} قيد الدراسة", "Your complaint {$c->ref_no} is under review", 'complaints');
        return $c;
    }

    public function close(Complaint $c, User $u, string $response): Complaint
    {
        if ($c->status === 'closed') throw new ApiException(L::t('الشكوى مغلقة.', 'The complaint is closed.'), 'closed', 409);
        $c->update(['status' => 'closed', 'response' => trim($response), 'responded_by' => $u->id, 'responded_at' => now()]);
        Notify::user(User::where('employee_id', $c->employee_id)->value('id'), "ردّت الموارد البشرية على شكواك {$c->ref_no}", "HR replied to your complaint {$c->ref_no}", 'complaints');
        Audit::record('complaint.closed', $c);
        return $c;
    }

    public static function row(Complaint $c, bool $withEmployee = true): array
    {
        return ['id' => $c->id, 'ref_no' => $c->ref_no, 'subject' => $c->subject, 'body' => $c->body, 'against' => $c->against, 'status' => $c->status, 'response' => $c->response,
            'responded_by' => $c->responder ? ['ar' => $c->responder->displayName('ar'), 'en' => $c->responder->displayName('en')] : null,
            'responded_at' => $c->responded_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String()]
            + ($withEmployee && $c->employee ? ['employee' => ['id' => $c->employee->id, 'code' => $c->employee->code, 'name' => ['ar' => $c->employee->name, 'en' => $c->employee->name_en],
                'title' => ['ar' => $c->employee->title_ar, 'en' => $c->employee->title_en]]] : []);
    }
}
