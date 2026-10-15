<?php

// يُدمج مع إعدادات Laravel الافتراضية — نكتب فقط ما نغيّره
return [
    'name' => env('APP_NAME', 'UHR'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Damascus'),
    'locale' => env('APP_LOCALE', 'ar'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    // عنوان الواجهة (روابط البريد: إعادة تعيين كلمة المرور، متابعة الطلب)
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5180'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'ar_SA'),
];
