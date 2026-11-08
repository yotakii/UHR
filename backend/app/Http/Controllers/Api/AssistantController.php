<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Services\Assistant\Actions;
use App\Services\Assistant\Assistant;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** «المساعد» (13) — للبوابتين: المحادثة، السؤال بالتدفق (NDJSON)، قرار بطاقات الإجراءات، والإعدادات */
class AssistantController extends Controller
{
    public function __construct(private Assistant $assistant, private Actions $actions) {}

    public function index(Request $r): JsonResponse
    {
        $u = $r->user(); $cfg = Assistant::config();
        return response()->json(['enabled' => (bool) $cfg['enabled'], 'summaries' => $this->assistant->available(), 'suggestions' => $this->assistant->suggestions($u),
            'history' => $this->assistant->history($u), 'limit' => ['used' => Assistant::usedToday($u), 'max' => (int) $cfg['daily_limit']]]);
    }

    /** السؤال: سطر JSON لكل حدث — reply ثم delta… ثم done */
    public function ask(Request $r): StreamedResponse
    {
        $u = $r->user();
        $text = Assistant::guard($u, (string) $r->input('text'));
        // يُشغَّل التدفق بعد انتهاء الوسائط (middleware) — نعيد سياق الشركة واللغة داخله
        $company = \App\Support\Tenant::id(); $locale = app()->getLocale();
        return response()->stream(function () use ($u, $text, $company, $locale) {
            app()->setLocale($locale);
            \App\Support\Tenant::run($company, fn () => $this->run($u, $text));
        }, 200, ['Content-Type' => 'application/x-ndjson; charset=utf-8', 'Cache-Control' => 'no-cache, no-transform', 'X-Accel-Buffering' => 'no']);
    }

    private function run($u, string $text): void
    {
            @ini_set('zlib.output_compression', '0');
            $emit = function (array $ev) { echo json_encode($ev, JSON_UNESCAPED_UNICODE)."\n"; if (ob_get_level() > 0) @ob_flush(); flush(); };
            try { $this->assistant->ask($u, $text, $emit); }
            catch (\Throwable $e) { report($e); $emit(['t' => 'error', 'message' => L::t('تعذّر إكمال الرد الآن. حاول مرة أخرى.', 'Could not complete the reply. Please try again.')]); }
    }

    public function action(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['decision' => ['required', 'in:preview,confirm,cancel'], 'fields' => ['nullable', 'array'], 'digest' => ['nullable', 'string', 'max:64']]);
        $m = AssistantMessage::findOrFail($id);
        $this->actions->decide($r->user(), $m, $d['decision'], $d['fields'] ?? [], $d['digest'] ?? null);
        return response()->json(['message' => Assistant::row($m->refresh())]);
    }

    public function clear(Request $r): JsonResponse
    {
        AssistantMessage::where('user_id', $r->user()->id)->delete();
        return response()->json(['ok' => true]);
    }

    public function settings(Request $r): JsonResponse
    {
        $month = AssistantMessage::where('role', 'user')->where('created_at', '>=', now()->startOfMonth());
        $done = AssistantMessage::where('role', 'assistant')->where('created_at', '>=', now()->startOfMonth())->whereRaw("data->'action'->>'status' = 'done'")->count();
        return response()->json(['data' => Assistant::config(), 'defaults' => Settings::DEFAULTS['assistant'], 'summaries_ready' => $this->assistant->available(),
            'usage' => ['today' => AssistantMessage::where('role', 'user')->where('created_at', '>=', now()->startOfDay())->count(), 'month' => (clone $month)->count(),
                'users' => (clone $month)->distinct('user_id')->count('user_id'), 'actions' => $done,
                'unanswered' => AssistantMessage::where('role', 'assistant')->where('intent', 'unknown')->where('created_at', '>=', now()->startOfMonth())->count()]]);
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $d = $r->validate(['enabled' => ['required', 'boolean'], 'summaries' => ['required', 'boolean'], 'actions' => ['required', 'array'],
            'actions.leave' => ['required', 'boolean'], 'actions.justify' => ['required', 'boolean'], 'actions.certificate' => ['required', 'boolean'], 'actions.praise' => ['required', 'boolean'],
            'daily_limit' => ['required', 'integer', 'between:1,500'], 'history_days' => ['required', 'integer', 'between:1,365']],
            [], ['daily_limit' => L::t('الحد اليومي', 'Daily limit'), 'history_days' => L::t('مدة الاحتفاظ', 'Keep for')]);
        $before = Assistant::config();
        Settings::put('assistant', ['enabled' => $d['enabled'], 'summaries' => $d['summaries'], 'actions' => array_intersect_key($d['actions'], Settings::DEFAULTS['assistant']['actions']),
            'daily_limit' => $d['daily_limit'], 'history_days' => $d['history_days']]);
        Audit::record('settings.assistant', null, $before, Assistant::config());
        return $this->settings($r);
    }
}
