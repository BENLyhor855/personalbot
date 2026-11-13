<?php
return [
 'token' => env('TELEGRAM_BOT_TOKEN'),
 'secret' => env('TELEGRAM_WEBHOOK_SECRET'),
 'url' => env('TELEGRAM_WEBHOOK_URL'),
 'allowed_ids' => array_filter(array_map('trim', explode(',', env('TELEGRAM_ALLOWED_USER_IDS', '')))),
 'primary' => env('AI_PRIMARY_DRIVER', 'cloud'),
 'cloud' => ['url' => env('AI_CLOUD_BASE_URL', 'https://api.openai.com/v1'), 'key' => env('AI_CLOUD_API_KEY'), 'model' => env('AI_CLOUD_MODEL')],
 'local' => ['url' => env('AI_LOCAL_BASE_URL', 'http://127.0.0.1:11434/v1'), 'key' => env('AI_LOCAL_API_KEY', 'ollama'), 'model' => env('AI_LOCAL_MODEL', 'qwen3:8b')],
];
