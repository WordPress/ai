<?php

declare(strict_types=1);

namespace WordPress\AiClient\Results;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Results\ValueObjects\CandidateDelta;
use WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk;
use WordPress\AiClient\Results\ValueObjects\ToolCallDelta;
use WordPress\AiClient\Tools\DTO\FunctionCall;

/**
 * Accumulates streamed chunks into a single result.
 *
 * @since n.e.x.t
 */
final class ChunkAccumulator
{
    /**
     * @var list<string> Canonical channel order for assembled parts, matching the buffered parser.
     */
    private const CANONICAL_CHANNEL_ORDER = [
        MessagePartChannelEnum::THOUGHT,
        MessagePartChannelEnum::CONTENT,
    ];

    private ProviderMetadata $providerMetadata;

    private ModelMetadata $modelMetadata;

    /**
     * @var string|null The result id, captured from the first chunk that carries one.
     */
    private ?string $id = null;

    /**
     * @var TokenUsage|null The token usage, captured from the chunk that carries it.
     */
    private ?TokenUsage $tokenUsage = null;

    /**
     * @var array<string, mixed> Merged result-level provider metadata.
     */
    private array $additionalData = [];

    /**
     * @var array<int, bool> Candidate indices seen while accumulating.
     */
    private array $candidates = [];

    /**
     * @var array<int, array<string, string>> Accumulated text, per candidate, per channel.
     */
    private array $text = [];

    /**
     * @var array<int, array<string, string>> Thought signature, per candidate, per channel.
     */
    private array $thoughtSignatures = [];

    /**
     * @var array<int, list<MessagePart>> Non-text parts, per candidate, in arrival order.
     */
    private array $otherParts = [];

    /**
     * @var array<int, FinishReasonEnum> Finish reason, per candidate.
     */
    private array $finishReasons = [];

    /**
     * Tool call slots being stitched together, per candidate, per slot index.
     *
     * @var array<int, array<int, array{id: string|null, name: string|null, args: string}>>
     */
    private array $toolCalls = [];

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param ProviderMetadata $providerMetadata Provider metadata for the assembled result.
     * @param ModelMetadata $modelMetadata Model metadata for the assembled result.
     */
    public function __construct(ProviderMetadata $providerMetadata, ModelMetadata $modelMetadata)
    {
        $this->providerMetadata = $providerMetadata;
        $this->modelMetadata = $modelMetadata;
    }

    /**
     * Folds a single chunk into the accumulated state.
     *
     * @since n.e.x.t
     *
     * @param GenerativeAiResultChunk $chunk The chunk to fold in.
     * @return void
     */
    public function add(GenerativeAiResultChunk $chunk): void
    {
        $id = $chunk->getId();
        if ($id !== null && $this->id === null) {
            $this->id = $id;
        }

        $tokenUsage = $chunk->getTokenUsage();
        if ($tokenUsage !== null) {
            $this->tokenUsage = $tokenUsage;
        }

        $additionalData = $chunk->getAdditionalData();
        if ($additionalData !== []) {
            $this->additionalData = array_merge($this->additionalData, $additionalData);
        }

        foreach ($chunk->getCandidateDeltas() as $candidateDelta) {
            $this->addCandidateDelta($candidateDelta);
        }
    }

    /**
     * Reports whether any candidate has been accumulated.
     *
     * @since n.e.x.t
     *
     * @return bool True if there is at least one candidate to build.
     */
    public function hasCandidates(): bool
    {
        return $this->candidates !== [];
    }

    /**
     * Assembles the accumulated state into a result.
     *
     * @since n.e.x.t
     *
     * @return GenerativeAiResult The assembled result.
     * @throws RuntimeException If no candidates were accumulated.
     */
    public function build(): GenerativeAiResult
    {
        if ($this->candidates === []) {
            throw new RuntimeException('The stream produced no candidates.');
        }

        $indices = array_keys($this->candidates);
        sort($indices);

        $candidates = [];
        foreach ($indices as $index) {
            $candidates[] = $this->buildCandidate($index);
        }

        return new GenerativeAiResult(
            $this->id ?? '',
            $candidates,
            $this->tokenUsage ?? new TokenUsage(0, 0, 0),
            $this->providerMetadata,
            $this->modelMetadata,
            $this->additionalData
        );
    }

    /**
     * Folds a candidate delta into the per-candidate state.
     *
     * @since n.e.x.t
     *
     * @param CandidateDelta $delta The candidate delta to fold in.
     * @return void
     */
    private function addCandidateDelta(CandidateDelta $delta): void
    {
        $index = $delta->getIndex();
        $this->candidates[$index] = true;

        $finishReason = $delta->getFinishReason();
        if ($finishReason !== null) {
            $this->finishReasons[$index] = $finishReason;
        }

        foreach ($delta->getParts() as $part) {
            $this->addPart($index, $part);
        }

        foreach ($delta->getToolCallDeltas() as $toolCallDelta) {
            $this->addToolCallDelta($index, $toolCallDelta);
        }
    }

    /**
     * Folds a content part into the candidate state.
     *
     * @since n.e.x.t
     *
     * @param int $index The candidate index.
     * @param MessagePart $part The part to fold in.
     * @return void
     */
    private function addPart(int $index, MessagePart $part): void
    {
        $text = $part->getText();
        if ($text === null) {
            $this->otherParts[$index][] = $part;
            return;
        }

        $channel = $part->getChannel()->value;
        if (!isset($this->text[$index][$channel])) {
            $this->text[$index][$channel] = '';
        }
        $this->text[$index][$channel] .= $text;

        $signature = $part->getThoughtSignature();
        if ($signature !== null) {
            $this->thoughtSignatures[$index][$channel] = $signature;
        }
    }

    /**
     * Stores a tool call fragment into the candidate's tool call slots.
     *
     * @since n.e.x.t
     *
     * @param int $index The candidate index.
     * @param ToolCallDelta $delta The tool call fragment.
     * @return void
     */
    private function addToolCallDelta(int $index, ToolCallDelta $delta): void
    {
        $slot = $delta->getIndex() ?? 0;

        if (!isset($this->toolCalls[$index][$slot])) {
            $this->toolCalls[$index][$slot] = ['id' => null, 'name' => null, 'args' => ''];
        }

        $id = $delta->getId();
        if ($id !== null && $this->toolCalls[$index][$slot]['id'] === null) {
            $this->toolCalls[$index][$slot]['id'] = $id;
        }

        $name = $delta->getFunctionName();
        if ($name !== null && $this->toolCalls[$index][$slot]['name'] === null) {
            $this->toolCalls[$index][$slot]['name'] = $name;
        }

        $this->toolCalls[$index][$slot]['args'] .= $delta->getArgumentsFragment();
    }

    /**
     * Builds a single candidate from its accumulated state.
     *
     * @since n.e.x.t
     *
     * @param int $index The candidate index.
     * @return Candidate The assembled candidate.
     */
    private function buildCandidate(int $index): Candidate
    {
        $parts = [];

        foreach ($this->orderedChannels($index) as $channel) {
            $parts[] = new MessagePart(
                $this->text[$index][$channel],
                MessagePartChannelEnum::from($channel),
                $this->thoughtSignatures[$index][$channel] ?? null
            );
        }

        foreach ($this->otherParts[$index] ?? [] as $part) {
            $parts[] = $part;
        }

        // Tool calls last, matching the non-streamed response part order.
        foreach ($this->buildToolCallParts($index) as $part) {
            $parts[] = $part;
        }

        $message = new Message(MessageRoleEnum::model(), $parts);
        $finishReason = $this->finishReasons[$index] ?? FinishReasonEnum::stop();

        return new Candidate($message, $finishReason);
    }

    /**
     * Returns the candidate's text channels in canonical order, with any unknown channel last.
     *
     * @since n.e.x.t
     *
     * @param int $index The candidate index.
     * @return list<string> The present channels, ordered.
     */
    private function orderedChannels(int $index): array
    {
        $present = array_keys($this->text[$index] ?? []);

        $ordered = [];
        foreach (self::CANONICAL_CHANNEL_ORDER as $channel) {
            if (in_array($channel, $present, true)) {
                $ordered[] = $channel;
            }
        }

        foreach ($present as $channel) {
            if (!in_array($channel, self::CANONICAL_CHANNEL_ORDER, true)) {
                // Add any unknown channel last, in arrival order in case the provider sends multiple unknown channels.
                // @codeCoverageIgnoreStart
                $ordered[] = $channel;
                // @codeCoverageIgnoreEnd
            }
        }

        return $ordered;
    }

    /**
     * Assembles the stored tool call slots for a candidate into message parts.
     *
     * @since n.e.x.t
     *
     * @param int $index The candidate index.
     * @return list<MessagePart> The assembled function call parts, in slot order.
     */
    private function buildToolCallParts(int $index): array
    {
        if (!isset($this->toolCalls[$index])) {
            return [];
        }

        $slots = $this->toolCalls[$index];
        ksort($slots);

        $parts = [];
        foreach ($slots as $slot) {
            // A function call needs at least an id or a name; skip a slot that received
            // neither (a malformed stream) rather than failing the whole result.
            if ($slot['id'] === null && $slot['name'] === null) {
                continue;
            }

            $parts[] = new MessagePart(
                new FunctionCall($slot['id'], $slot['name'], $this->decodeToolCallArgs($slot['args']))
            );
        }

        return $parts;
    }

    /**
     * Decodes accumulated tool call arguments.
     *
     * @since n.e.x.t
     *
     * @param string $arguments The accumulated arguments string.
     * @return mixed The decoded arguments, the raw string on failure, or null when empty.
     */
    private function decodeToolCallArgs(string $arguments)
    {
        if ($arguments === '') {
            return null;
        }

        $decoded = json_decode($arguments, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $arguments;
    }
}
