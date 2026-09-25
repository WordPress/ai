<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Models\TextGeneration\Contracts;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Results\StreamedGenerativeAiResult;

/**
 * Interface for models that support streaming text generation.
 *
 * @since n.e.x.t
 */
interface StreamingTextGenerationModelInterface
{
    /**
     * Streams generated text from a prompt.
     *
     * @since n.e.x.t
     *
     * @param list<Message> $prompt Array of messages containing the text generation prompt.
     * @return StreamedGenerativeAiResult The streamed result.
     */
    public function streamGenerateTextResult(array $prompt): StreamedGenerativeAiResult;
}
