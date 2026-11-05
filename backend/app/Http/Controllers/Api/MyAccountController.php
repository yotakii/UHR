<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Support\Audit;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «حسابي» (ملاحظة الاختبار 67) لكل مستخدم في البوابتين: الصورة الشخصية، اللغة والمظهر (تُحفظ في الحساب فتتبعه على كل جهاز)،
 * الاسم لحسابات بلا ملف موظف، وعرض المسمى والإدارة والمدير (تُعدَّل من الموارد البشرية أو بطلب تحديث بيانات).
 * تغيير كلمة المرور من /api/auth/password، وطرق التحقق من نافذة حماية الرواتب.
 */
class MyAccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $u = $request->user();
        $u->loadMissing(['roles', 'employee.department', 'employee.manager', 'employee.location']);
        $e = $u->employee;
        return response()->json(['data' => [
            'email' => $u->email, 'name' => ['ar' => $u->displayName('ar'), 'en' => $u->displayName('en')], 'own_name' => $u->name, 'name_en' => $u->name_en,
            'avatar' => $u->avatar_file_id, 'locale' => $u->locale, 'theme' => $u->theme, 'last_login_at' => $u->last_login_at?->toIso8601String(),
            'roles' => $u->roles->map(fn ($r) => ['key' => $r->key, 'ar' => $r->name_ar, 'en' => $r->name_en])->values(),
            'employee' => $e ? ['code' => $e->code, 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'dept' => ['ar' => $e->department?->name_ar, 'en' => $e->department?->name_en],
                'manager' => $e->manager ? ['ar' => $e->manager->name, 'en' => $e->manager->name_en] : null, 'location' => $e->location ? ['ar' => $e->location->name_ar, 'en' => $e->location->name_en] : null,
                'phone' => $e->phone, 'hire_date' => $e->hire_date?->toDateString()] : null,
            'password_policy' => \App\Support\PasswordPolicy::client($u->company_id),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate(['locale' => ['sometimes', Rule::in(['ar', 'en'])], 'theme' => ['sometimes', Rule::in(['slate', 'dark', 'light'])],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'], 'name_en' => ['sometimes', 'nullable', 'string', 'max:120']]);
        // الاسم لحسابات بلا ملف موظف فقط — اسم الموظف من ملفه الوظيفي
        if ($u->employee_id) unset($d['name'], $d['name_en']);
        elseif (array_key_exists('name', $d) && ! trim((string) $d['name'])) throw ApiException::field('name', L::t('الاسم مطلوب.', 'The name is required.'), 'required');
        $u->update($d);
        return response()->json(['user' => $u->refresh()->sessionPayload()]);
    }

    public function avatar(Request $request): JsonResponse
    {
        $u = $request->user();
        $f = $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp']], [
            'file.max' => L::t('حجم الصورة حتى 2 ميغابايت.', 'The image must be at most 2 MB.'), 'file.mimes' => L::t('الصورة بصيغة JPG أو PNG أو WEBP.', 'Use a JPG, PNG or WEBP image.')])['file'];
        $path = $f->store('avatars/'.($u->company_id ?? 'platform'), 'local');
        $file = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => 'avatar.'.$f->extension(), 'size' => $f->getSize(), 'mime' => $f->getMimeType(), 'uploaded_by' => $u->id]);
        $u->update(['avatar_file_id' => $file->id]);
        Audit::record('account.avatar', $u);
        return response()->json(['user' => $u->refresh()->sessionPayload()]);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        $u = $request->user();
        $u->update(['avatar_file_id' => null]);
        return response()->json(['user' => $u->refresh()->sessionPayload()]);
    }
}
