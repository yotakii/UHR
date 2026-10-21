<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\User;
use App\Services\Workflow\Workflow;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * التكليف بالعمل الإضافي HR.01-01-F-04 (SRS §10.6): بتكليف خطي مسبق من المدير المباشر (أو الموارد البشرية)، بحد يومي من السياسة،
 * ثم اعتماد الموارد البشرية (سلسلة overtime). يُطابَق مع الدوام في الكشف الشهري (07d).
 */
class OvertimeService
{
    public function __construct(private Workflow $flow) {}

    public function assign(User $by, Employee $e, string $date, int $minutes, string $reason): OvertimeRequest
    {
        $isManager = $by->employee_id && $e->manager_id === $by->employee_id;
        if (! $isManager && ! Perm::can($by, 'attendance', 'edit')) throw new ApiException(L::t('التكليف بالإضافي من المدير المباشر للموظف أو الموارد البشرية.', 'Overtime is assigned by the employee’s direct manager or HR.'), 'forbidden', 403);
        if ($e->id === $by->employee_id) throw new ApiException(L::t('لا يمكنك تكليف نفسك.', 'You cannot assign yourself.'), 'forbidden', 403);
        $max = (int) Settings::policy('overtime', $date)['daily_max'];
        if ($minutes < 15 || $minutes > $max) throw ApiException::field('minutes', L::t("المدة من 15 دقيقة حتى {$max} دقيقة باليوم.", "From 15 minutes up to {$max} minutes a day."), 'overtime_max');
        if ($date < now()->toDateString()) throw ApiException::field('date', L::t('التكليف مسبق — اليوم أو بعده.', 'The assignment is made in advance — today or later.'), 'overtime_past');
        if (OvertimeRequest::where('employee_id', $e->id)->whereDate('date', $date)->whereNotIn('status', ['rejected', 'cancelled'])->exists()) throw ApiException::field('date', L::t('يوجد تكليف لهذا الموظف في نفس اليوم.', 'This employee already has an assignment that day.'), 'overtime_exists');
        return DB::transaction(function () use ($by, $e, $date, $minutes, $reason) {
            $o = OvertimeRequest::create(['employee_id' => $e->id, 'date' => $date, 'minutes' => $minutes, 'reason' => $reason, 'requested_by' => $by->id, 'status' => 'pending_hr']);
            $e->log("تكليف بعمل إضافي ليوم {$date} ({$minutes} د) — {$by->displayName('ar')}", "Overtime assigned for {$date} ({$minutes} min) — {$by->displayName('en')}", 'info', 'F-04');
            $this->flow->start($o, $e, Workflow::chain('overtime'), $by);
            return $o->refresh();
        });
    }

    public function decide(OvertimeRequest $o, User $u, bool $approve, ?string $note): OvertimeRequest
    {
        return $this->flow->decide($o, $u, $approve, $note);
    }
}
