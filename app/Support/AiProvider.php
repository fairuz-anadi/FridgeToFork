<?php

namespace App\Support;

/**
 * Which AI service backs the chatbot and the fridge photo scan.
 *
 * AI_PROVIDER picks one explicitly ("anthropic" or "openai"). When it is not
 * set, whichever API key is configured is used, Anthropic first. Null means
 * no AI is configured, so the built-in assistant answers instead.
 */
class AiProvider
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI = 'openai';

    public static function current(): ?string
    {
        $chosen = strtolower((string) config('services.ai.provider'));

        if ($chosen === self::ANTHROPIC) {
            return config('services.anthropic.key') ? self::ANTHROPIC : null;
        }

        if ($chosen === self::OPENAI) {
            return config('services.openai.key') ? self::OPENAI : null;
        }

        if (config('services.anthropic.key')) {
            return self::ANTHROPIC;
        }

        return config('services.openai.key') ? self::OPENAI : null;
    }
}
