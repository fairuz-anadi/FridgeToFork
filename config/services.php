<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        // The browser is the OAuth client for Google Identity Services. Prefer
        // its public Vite ID so token audience validation cannot drift from the
        // client that initiated the sign-in flow.
        'client_id'     => env('VITE_GOOGLE_CLIENT_ID', env('GOOGLE_CLIENT_ID')),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri'  => env('APP_URL', 'http://localhost:8000') . '/api/auth/google/callback',
    ],

    /*
     | Nutrition Insights. `local` uses the bundled per-ingredient table and
     | needs no credentials; `spoonacular` and `edamam` call out to the
     | third-party APIs named in the project proposal and fall back to the
     | local estimate if the request fails.
     */
    'nutrition' => [
        'provider' => env('NUTRITION_PROVIDER', 'local'),
        'spoonacular_key' => env('SPOONACULAR_KEY'),
        'edamam_app_id' => env('EDAMAM_APP_ID'),
        'edamam_app_key' => env('EDAMAM_APP_KEY'),
    ],

    /*
     | The recipe chatbot and the fridge photo scan use an AI provider when an
     | API key is configured: Anthropic (Claude) or OpenAI. AI_PROVIDER picks
     | one explicitly; otherwise whichever key is set is used. Without a key
     | the chatbot falls back to the built-in rule-based assistant.
     */
    'ai' => [
        'provider' => env('AI_PROVIDER'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
        'base_url' => env('ANTHROPIC_BASE_URL'),
    ],

];
