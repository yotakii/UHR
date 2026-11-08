<?php

// يُدمج مع إعدادات Laravel الافتراضية
return [
    // قراءة السير بنموذج محلي مجاني (خدمة ollama على الخادم) — بلا عنوان تبقى الميزة متوقفة
    'ollama' => [
        'url' => env('OLLAMA_URL'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:3b'),
        'timeout' => (int) env('OLLAMA_TIMEOUT', 420),
        'num_ctx' => (int) env('OLLAMA_NUM_CTX', 2048),
        'threads' => (int) env('OLLAMA_THREADS', 0),   // 0 = تلقائي
        'num_predict' => (int) env('OLLAMA_NUM_PREDICT', 240),
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),  // يبقى النموذج محمّلاً فلا يُعاد تحميله لكل سيرة
        'cv_chars' => (int) env('OLLAMA_CV_CHARS', 2000),
        'format' => env('OLLAMA_FORMAT', 'none'),        // none | json | schema
        // «المساعد»: ملخص قصير بالتدفق من نفس النموذج
        'assistant_ctx' => (int) env('OLLAMA_ASSISTANT_CTX', 2048),
        'assistant_predict' => (int) env('OLLAMA_ASSISTANT_PREDICT', 180),
        'assistant_timeout' => (int) env('OLLAMA_ASSISTANT_TIMEOUT', 90),
    ],
];
