<?php

// يُدمج مع إعدادات Laravel الافتراضية
return [
    // قراءة السير بنموذج محلي مجاني (خدمة ollama على الخادم) — بلا عنوان تبقى الميزة متوقفة
    'ollama' => [
        'url' => env('OLLAMA_URL'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:3b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 300),
    ],
];
