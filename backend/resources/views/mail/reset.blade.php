<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f3f5f4;font-family:Tahoma,Arial,sans-serif;color:#17221e">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #dfe5e2;border-radius:14px">
  <tr><td style="padding:22px 28px;border-bottom:1px solid #eef1ef;font-weight:bold;letter-spacing:.2em;font-size:12px;color:#0f7a5c" dir="ltr">UHR · {{ $company['en'] }}</td></tr>
  <tr><td style="padding:26px 28px" dir="rtl">
    <h1 style="margin:0 0 10px;font-size:20px">إعادة تعيين كلمة المرور</h1>
    <p style="margin:0 0 6px;line-height:1.8">@if($name)مرحباً {{ $name }}،@endif وصلنا طلب لإعادة تعيين كلمة مرور حسابك في نظام الموارد البشرية لدى {{ $company['ar'] }}.</p>
    <p style="margin:0 0 18px;line-height:1.8;color:#5b6863">الرابط صالح لمدة {{ $minutes }} دقيقة. إذا لم تطلب ذلك تجاهل هذه الرسالة وستبقى كلمة مرورك كما هي.</p>
    <a href="{{ $url }}" style="display:inline-block;background:#0f7a5c;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px">تعيين كلمة مرور جديدة</a>
  </td></tr>
  <tr><td style="padding:22px 28px;border-top:1px solid #eef1ef" dir="ltr">
    <h2 style="margin:0 0 8px;font-size:16px">Reset your password</h2>
    <p style="margin:0 0 16px;line-height:1.6;color:#5b6863">We received a request to reset your UHR password at {{ $company['en'] }}. The link is valid for {{ $minutes }} minutes. If you didn't ask for this, ignore this email.</p>
    <a href="{{ $url }}" style="color:#0f7a5c;font-weight:bold">Set a new password →</a>
  </td></tr>
  <tr><td style="padding:14px 28px;font-size:11px;color:#8a9691;border-top:1px solid #eef1ef;word-break:break-all" dir="ltr">{{ $url }}</td></tr>
</table></td></tr></table>
</body></html>
