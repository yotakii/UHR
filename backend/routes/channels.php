<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// قنوات البث اللحظي (Reverb) — الحمولة بلا بيانات حساسة، والاشتراك بالجلسة
// قناة الشركة: لمستخدمي نظام الشركة (portal hr) — تحديث الشاشات وإشعارات الموارد البشرية
Broadcast::channel('company.{id}', fn (User $u, int $id) => $u->active && $u->company_id === $id && $u->portal() === 'hr');
// قناة المستخدم: إشعاراته الشخصية (الموظف، المتقدم، أي حساب)
Broadcast::channel('user.{id}', fn (User $u, int $id) => $u->active && $u->id === $id);
