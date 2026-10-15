{{-- رسالة نظام عامة ثنائية اللغة: العربية أولاً ثم الإنكليزية --}}
<!doctype html>
<html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;background:#f3f5f4;font-family:Tahoma,Arial,sans-serif;color:#17221e">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border:1px solid #dfe5e2;border-radius:14px">
  <tr><td style="padding:22px 28px;border-bottom:1px solid #eef1ef;font-weight:bold;letter-spacing:.2em;font-size:12px;color:#0f7a5c" dir="ltr">UHR · {{ $company['en'] }}</td></tr>
  <tr><td style="padding:26px 28px" dir="rtl">
    <h1 style="margin:0 0 10px;font-size:20px">{{ $ar['title'] }}</h1>
    <p style="margin:0 0 6px;line-height:1.8">@if($name)مرحباً {{ $name }}، @endif{{ $ar['body'] }}</p>
    @isset($ref)<p style="margin:12px 0;font-size:18px;font-weight:bold;letter-spacing:.05em" dir="ltr">{{ $ref }}</p>@endisset
    <p style="margin:0 0 18px;line-height:1.8;color:#5b6863">{{ $ar['hint'] }}</p>
    <a href="{{ $url }}" style="display:inline-block;background:#0f7a5c;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:10px">{{ $ar['action'] }}</a>
  </td></tr>
  <tr><td style="padding:22px 28px;border-top:1px solid #eef1ef" dir="ltr">
    <h2 style="margin:0 0 8px;font-size:16px">{{ $en['title'] }}</h2>
    <p style="margin:0 0 8px;line-height:1.6;color:#5b6863">{{ $en['body'] }}</p>
    <p style="margin:0 0 16px;line-height:1.6;color:#5b6863">{{ $en['hint'] }}</p>
    <a href="{{ $url }}" style="color:#0f7a5c;font-weight:bold">{{ $en['action'] }} →</a>
  </td></tr>
  <tr><td style="padding:14px 28px;font-size:11px;color:#8a9691;border-top:1px solid #eef1ef" dir="ltr">{{ $company['ar'] }} · {{ $company['en'] }}</td></tr>
</table></td></tr></table>
</body></html>
