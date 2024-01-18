<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\OpenAIPHP;

final class OpenAIAttributes
{
    const OPENAI_MODEL = 'openai.model';
    const OPENAI_RESOURCE = 'openai.resource';
    const OPENAI_RESPONSE_FORMAT = 'openai.response_format';
    const OPENAI_TEMPERATURE = 'openai.temperature';
    const OPENAI_FREQUENCY_PENALTY = 'openai.frequency_penalty';
    const OPENAI_MAX_TOKENS = 'openai.max_tokens';
    const OPENAI_N = 'openai.n';
    const OPENAI_PRESENCE_PENALTY = 'openai.presence_penalty';
    const OPENAI_SEED = 'openai.seed';
    const OPENAI_STREAM = 'openai.stream';
    const OPENAI_TOP_P = 'openai.top_p';
    const OPENAI_USER = 'openai.user';
    const OPENAI_USAGE_PROMPT_TOKENS = 'openai.usage.prompt_tokens';
    const OPENAI_USAGE_COMPLETION_TOKENS = 'openai.usage.completion_tokens';
    const OPENAI_USAGE_TOTAL_TOKENS = 'openai.usage.total_tokens';
}
