<?php

// يُدمج مع إعدادات Laravel الافتراضية
return [
    // قراءة السير بنموذج محلي مجاني (خدمة ollama على الخادم) — بلا عنوان تبقى الميزة متوقفة
    'ollama' => [
        'url' => env('OLLAMA_URL'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:3b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 420),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 3072),
        'threads' => (int) env('OLLAMA_THREADS', 0),   // 0 = تلقائي
    ],
];
