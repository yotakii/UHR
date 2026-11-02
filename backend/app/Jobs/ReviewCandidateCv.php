<?php

namespace App\Jobs;

use App\Models\CandidateReview;
use App\Services\Recruitment\CvReview;
use App\Support\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** قراءة سيرة واحدة على طابور «ai» (عامل مستقل حتى لا يتأخر البريد) */
class ReviewCandidateCv implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;
    public int $backoff = 60;

    public function __construct(public int $reviewId) {}

    public function handle(CvReview $svc): void
    {
        $r = CandidateReview::allCompanies()->find($this->reviewId);
        if (! $r) return;
        Tenant::run($r->company_id, fn () => $svc->run($r));
    }

    /** انتهت المحاولات (أو قُطعت لتجاوز الوقت): لا تبقى «جارية» للأبد */
    public function failed(\Throwable $e): void
    {
        CandidateReview::allCompanies()->whereKey($this->reviewId)->whereIn('status', ['pending', 'running'])
            ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
    }
}
