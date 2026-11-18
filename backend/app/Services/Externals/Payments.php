<?php

namespace App\Services\Externals;

use App\Exceptions\ApiException;
use App\Models\External;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;

/** طلبات الدفع للمالية خارج مسيّر الرواتب: اعتماد ← دفع برقم سند المالية، أو رفض بسبب */
class Payments
{
    public static function create(External $x, string $type, ?int $sourceId, string $desc, float $amount, string $currency, ?int $fileId, ?User $u): PaymentRequest
    {
        $p = PaymentRequest::create(['ref' => 'PAY-'.now()->year.'-'.str_pad((string) Counters::next('ext-pay-'.now()->year), 4, '0', STR_PAD_LEFT), 'external_id' => $x->id, 'source_type' => $type, 'source_id' => $sourceId,
            'description' => mb_substr($desc, 0, 255), 'amount' => $amount, 'currency' => $currency, 'file_id' => $fileId, 'status' => 'pending', 'created_by' => $u?->id]);
        Notify::hr("طلب دفع جديد {$p->ref} لـ {$x->name}", "New payment request {$p->ref} for {$x->name}", 'externals-pay');
        return $p;
    }

    public static function canDecide(User $u): bool { $u->loadMissing('roles'); return $u->hasRole('finance', 'admin') || Perm::can($u, 'externals', 'decide'); }

    public function decide(PaymentRequest $p, string $action, ?string $ref, ?string $note, User $u): PaymentRequest
    {
        if (! self::canDecide($u)) abort(403);
        $ok = ['approve' => ['pending'], 'pay' => ['pending', 'approved'], 'reject' => ['pending', 'approved']][$action] ?? throw new ApiException(L::t('إجراء غير معروف.', 'Unknown action.'), 'invalid', 422);
        if (! in_array($p->status, $ok, true)) throw new ApiException(L::t('لا يمكن هذا الإجراء في حالة الطلب الحالية.', 'Not possible in the current status.'), 'invalid_status', 409);
        if ($action === 'pay' && mb_strlen(trim((string) $ref)) < 2) throw ApiException::field('finance_ref', L::t('رقم سند الدفع مطلوب.', 'The payment voucher number is required.'), 'required');
        if ($action === 'reject' && mb_strlen(trim((string) $note)) < 5) throw ApiException::field('note', L::t('اكتب سبب الرفض.', 'Write the reason.'), 'reason_required');
        $p->update(['status' => ['approve' => 'approved', 'pay' => 'paid', 'reject' => 'rejected'][$action], 'decided_by' => $u->id, 'decided_at' => now(), 'note' => $note ?? $p->note]
            + ($action === 'pay' ? ['finance_ref' => $ref, 'paid_at' => now()] : []));
        Audit::record('payment_request.'.$action, $p);
        $x = External::find($p->external_id);
        if ($x?->user_id && $action !== 'approve') Notify::user($x->user_id, $action === 'pay' ? "صُرف المبلغ {$p->ref}" : "رُفض طلب الدفع {$p->ref}: {$note}", $action === 'pay' ? "Payment {$p->ref} made" : "Payment request {$p->ref} rejected: {$note}", 'ext');
        return $p;
    }
}
