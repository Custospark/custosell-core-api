<?php

return [
    /*
    | Chat assistant provider. OpenAI-compatible endpoint so the same code
    | works against OpenRouter (free contributor tier) or Meta Model API.
    */
    'base_url' => env('ASSISTANT_BASE_URL', 'https://openrouter.ai/api/v1'),
    'api_key' => env('OPENROUTER_API_KEY', ''),
    'model' => env('ASSISTANT_MODEL', 'meta/muse-spark-1.3-contributor'),
    'timeout' => (int) env('ASSISTANT_TIMEOUT', 60),
    'max_tokens' => (int) env('ASSISTANT_MAX_TOKENS', 1000),
    'max_messages' => (int) env('ASSISTANT_MAX_MESSAGES', 20),
    'max_chars_per_message' => (int) env('ASSISTANT_MAX_CHARS', 2000),
];
