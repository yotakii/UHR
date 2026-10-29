<?php

namespace App\Services\Engagement;

use App\Exceptions\ApiException;
use App\Models\Announcement;
use App\Models\Employee;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * الإعلانات (D-06، SRS §10.12): نص وصورة أو مرفق، للكل أو لإدارة، بتاريخ نشر وانتهاء.
 * الحالات: draft ← pending (إن فُعّل الاعتماد قبل النشر في الإعدادات) ← published ← expired (تلقائياً).
 * عند النشر الفعلي يُشعَر المستهدفون مرة واحدة، والإعلان لإدارة لا يراه غيرها.
 */
class Announcements
{
    public const TAGS = ['people', 'payroll', 'training', 'policy', 'event'];

    public static function config(): array { return array_merge(Settings::DEFAULTS['announcements'], Settings::get('announcements') ?? []); }

    public function save(?Announcement $a, array $in, ?UploadedFile $image, ?UploadedFile $file, User $by, bool $submit): Announcement
    {
        if ($a && in_array($a->status, ['published', 'expired'], true) && $a->published_at?->isPast()) throw new ApiException(L::t('الإعلان المنشور لا يُعدَّل — أنشئ إعلاناً جديداً أو أرشفه.', 'A published announcement cannot be edited — archive it or post a new one.'), 'published', 409);
        $d = Validator::make($in, ['title_ar' => ['required', 'string', 'min:3', 'max:190'], 'title_en' => ['nullable', 'string', 'max:190'], 'text_ar' => ['required', 'string', 'min:5', 'max:5000'],
            'text_en' => ['nullable', 'string', 'max:5000'], 'tag' => ['required', 'in:'.implode(',', self::TAGS)], 'audience' => ['required', 'in:all,department'],
            'department_id' => ['nullable', 'required_if:audience,department', 'integer'], 'publish_at' => ['nullable', 'date'], 'expires_at' => ['nullable', 'date', ! empty($in['publish_at']) ? 'after:publish_at' : 'after:now']],
            ['expires_at.after' => L::t('تاريخ الانتهاء بعد تاريخ النشر.', 'The expiry date must be after the publish date.')],
            ['title_ar' => L::t('العنوان', 'Title'), 'text_ar' => L::t('النص', 'Text'), 'department_id' => L::t('الإدارة', 'Department')])->validate();
        if (($d['audience'] ?? 'all') === 'department' && ! \App\Models\Department::whereKey($d['department_id'])->exists()) throw ApiException::field('department_id', L::t('اختر الإدارة.', 'Choose the department.'), 'invalid');
        $max = (int) self::config()['max_mb'];
        $store = function (?UploadedFile $f, array $mimes, string $field) use ($max, $by) {
            if (! $f) return null;
            if ($f->getSize() > $max * 1024 * 1024 || ! in_array(strtolower($f->getClientOriginalExtension()), $mimes, true)) {
                throw ApiException::field($field, L::t("الصيغ المسموحة: ".implode('، ', $mimes)." حتى {$max} MB.", 'Allowed: '.implode(', ', $mimes)." up to {$max} MB."), 'bad_file');
            }
            $path = $f->storeAs('announcements/'.now()->format('Y/m'), Str::uuid().'.'.strtolower($f->getClientOriginalExtension()), 'local');
            return StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => $f->getClientOriginalName(), 'size' => $f->getSize(), 'mime' => $f->getMimeType(), 'uploaded_by' => $by->id])->id;
        };
        $row = ['title_ar' => trim($d['title_ar']), 'title_en' => trim((string) ($d['title_en'] ?? '')) ?: null, 'text_ar' => trim($d['text_ar']), 'text_en' => trim((string) ($d['text_en'] ?? '')) ?: trim($d['text_ar']),
            'tag' => $d['tag'], 'audience' => $d['audience'], 'department_id' => $d['audience'] === 'department' ? (int) $d['department_id'] : null,
            'published_at' => ! empty($d['publish_at']) ? $d['publish_at'] : now(), 'expires_at' => $d['expires_at'] ?? null];
        if ($id = $store($image, ['jpg', 'jpeg', 'png', 'webp'], 'image')) $row['image_file_id'] = $id;
        if ($id = $store($file, ['pdf', 'docx', 'xlsx', 'jpg', 'png'], 'attachment')) $row['attachment_file_id'] = $id;
        $needs = (bool) self::config()['require_approval'] && ! Perm::can($by, 'announcements', 'decide');
        $row['status'] = ! $submit ? 'draft' : ($needs ? 'pending' : 'published');
        if ($row['status'] === 'published') $row += ['approved_by' => $by->id, 'approved_at' => now()];
        $a = $a ? tap($a)->update($row) : Announcement::create($row + ['created_by' => $by->id]);
        if ($row['status'] === 'pending') Notify::hr("إعلان «{$a->title_ar}» بانتظار الاعتماد قبل النشر", "Announcement «".($a->title_en ?: $a->title_ar)."» awaits approval", 'announcements');
        $this->deliver($a->refresh());
        return $a;
    }

    /** اعتماد أو إرجاع (صاحب صلاحية «اعتماد» في الإعلانات، وليس كاتبه) */
    public function decide(Announcement $a, User $u, bool $approve, ?string $note): Announcement
    {
        if ($a->status !== 'pending') throw new ApiException(L::t('الإعلان ليس بانتظار الاعتماد.', 'The announcement is not pending.'), 'not_pending', 409);
        if ($a->created_by === $u->id) throw new ApiException(L::t('لا تعتمد إعلانك.', 'You cannot approve your own announcement.'), 'forbidden', 403);
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        if (! $approve && mb_strlen(trim((string) $note)) < $min) throw ApiException::field('note', L::t("سبب الإرجاع إلزامي ({$min} أحرف على الأقل).", "A reason is required (at least {$min} characters)."), 'reason_required');
        $a->update($approve ? ['status' => 'published', 'approved_by' => $u->id, 'approved_at' => now()] : ['status' => 'draft']);
        Notify::user($a->created_by, $approve ? "اعتُمد إعلان «{$a->title_ar}»" : "أُرجع إعلان «{$a->title_ar}»: {$note}", $approve ? 'Your announcement was approved' : "Your announcement was returned: {$note}", 'announcements');
        $this->deliver($a->refresh());
        return $a;
    }

    public function archive(Announcement $a): Announcement
    {
        $a->update(['status' => 'expired', 'archived_at' => now()]);
        return $a;
    }

    /** إشعار المستهدفين عند حلول تاريخ النشر — مرة واحدة */
    public function deliver(Announcement $a): void
    {
        if ($a->status !== 'published' || $a->notified_at || $a->published_at?->isFuture()) return;
        $users = User::where('company_id', \App\Support\Tenant::id())->where('active', true)->whereNotNull('employee_id')
            ->when($a->audience === 'department', fn ($q) => $q->whereIn('employee_id', Employee::where('department_id', $a->department_id)->select('id')))->pluck('id');
        foreach ($users as $id) Notify::user($id, "إعلان: {$a->title_ar}", 'Announcement: '.($a->title_en ?: $a->title_ar), 'announcements');
        $a->update(['notified_at' => now()]);
    }

    /** كل 10 دقائق: نشر المجدول، وأرشفة المنتهي */
    public function sweep(): array
    {
        $n = ['published' => 0, 'expired' => 0];
        Announcement::where('status', 'published')->whereNull('notified_at')->where('published_at', '<=', now())->get()->each(function ($a) use (&$n) { $this->deliver($a); $n['published']++; });
        $n['expired'] = Announcement::where('status', 'published')->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['status' => 'expired', 'archived_at' => now()]);
        return $n;
    }

    /** إعلانات الموظف: المنشورة الحالية للكل أو لإدارته */
    public static function feed(?Employee $e, int $limit = 30)
    {
        return Announcement::with(['image', 'attachment', 'department'])->where('status', 'published')->where('published_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->where(fn ($q) => $q->where('audience', 'all')->orWhere(fn ($w) => $w->where('audience', 'department')->where('department_id', $e?->department_id ?? 0)))
            ->orderByDesc('published_at')->limit($limit)->get();
    }

    public static function row(Announcement $a, bool $admin = false): array
    {
        $r = ['id' => $a->id, 'title' => ['ar' => $a->title_ar ?: mb_substr($a->text_ar, 0, 60), 'en' => $a->title_en ?: $a->title_ar ?: mb_substr($a->text_en, 0, 60)], 'text' => ['ar' => $a->text_ar, 'en' => $a->text_en],
            'tag' => $a->tag, 'audience' => $a->audience, 'department' => $a->department ? ['id' => $a->department->id, 'ar' => $a->department->name_ar, 'en' => $a->department->name_en] : null,
            'image' => $a->image_file_id, 'attachment' => $a->attachment ? ['id' => $a->attachment->id, 'name' => $a->attachment->name] : null,
            'published_at' => $a->published_at?->toIso8601String(), 'expires_at' => $a->expires_at?->toIso8601String(), 'status' => $a->status];
        if ($admin) $r += ['title_ar' => $a->title_ar, 'title_en' => $a->title_en, 'text_ar' => $a->text_ar, 'text_en' => $a->text_en, 'department_id' => $a->department_id,
            'author' => $a->author ? ['ar' => $a->author->displayName('ar'), 'en' => $a->author->displayName('en')] : null, 'notified_at' => $a->notified_at?->toIso8601String(),
            'created_by' => $a->created_by];
        return $r;
    }
}
