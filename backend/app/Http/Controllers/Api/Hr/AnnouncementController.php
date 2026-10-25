<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Engagement\Announcements;
use App\Support\Audit;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** الإعلانات (D-06): الإدارة (كل الحالات) والاعتماد الاختياري؛ والموظف يرى المنشور له فقط */
class AnnouncementController extends Controller
{
    private const WITH = ['image', 'attachment', 'department', 'author.employee'];

    public function __construct(private Announcements $ann) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json(['data' => Announcement::with(self::WITH)->orderByRaw("case status when 'pending' then 0 when 'draft' then 1 when 'published' then 2 else 3 end")->orderByDesc('published_at')->limit(200)->get()
            ->map(fn ($a) => Announcements::row($a, true)),
            'departments' => Department::orderBy('id')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'tags' => Announcements::TAGS, 'config' => Announcements::config(), 'reject_min' => (int) Settings::policy('leave_rules')['reject_min'],
            'can' => ['create' => Perm::can($u, 'announcements', 'create'), 'decide' => Perm::can($u, 'announcements', 'decide'), 'archive' => Perm::can($u, 'announcements', 'archive'), 'settings' => Perm::can($u, 'settings', 'edit')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $a = $this->ann->save(null, $request->except(['image', 'attachment']), $request->file('image'), $request->file('attachment'), $request->user(), $request->boolean('submit', true));
        return response()->json(['data' => Announcements::row($a->load(self::WITH), true)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $a = $this->ann->save(Announcement::findOrFail($id), $request->except(['image', 'attachment']), $request->file('image'), $request->file('attachment'), $request->user(), $request->boolean('submit', true));
        return response()->json(['data' => Announcements::row($a->load(self::WITH), true)]);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $a = $this->ann->decide(Announcement::findOrFail($id), $request->user(), (bool) $d['approve'], $d['note'] ?? null);
        return response()->json(['data' => Announcements::row($a->load(self::WITH), true)]);
    }

    public function archive(int $id): JsonResponse
    {
        return response()->json(['data' => Announcements::row($this->ann->archive(Announcement::findOrFail($id))->load(self::WITH), true)]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['require_approval' => ['required', 'boolean'], 'max_mb' => ['required', 'integer', 'between:1,25']]);
        $before = Announcements::config();
        Settings::put('announcements', $d);
        Audit::record('settings.announcements', null, $before, $d);
        return response()->json(['config' => Announcements::config()]);
    }

    /** إعلانات الموظف (بوابة الموظف وحسابات HR التي لها ملف موظف) */
    public function mine(Request $request): JsonResponse
    {
        $e = $request->user()->employee_id ? Employee::find($request->user()->employee_id) : null;
        return response()->json(['data' => Announcements::feed($e)->map(fn ($a) => Announcements::row($a))]);
    }
}
