<?php

declare(strict_types=1);

namespace WordPress\AiClient\Results\ValueObjects;

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Represents one candidate's delta within a streamed chunk.
 *
 * @todo Make this class readonly once php 8.2 is the minimum requirement.
 *
 * @since n.e.x.t
 */
final class CandidateDelta
{
    /**
     * @var int The candidate index this delta contributes to.
     */
    private int $index;

    /**
     * @var list<MessagePart> The partial content parts for this candidate.
     */
    private array $parts;

    /**
     * @var FinishReasonEnum|null The finish reason, when this delta reports it.
     */
    private ?FinishReasonEnum $finishReason;

    /**
     * @var list<ToolCallDelta> The partial tool calls for this candidate.
     */
    private array $toolCallDeltas;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param int $index The candidate index this delta contributes to.
     * @param list<MessagePart> $parts The partial content parts.
     * @param FinishReasonEnum|null $finishReason The finish reason, when reported.
     * @param list<ToolCallDelta> $toolCallDeltas The partial tool calls.
     */
    public function __construct(
        int $index,
        array $parts = [],
        ?FinishReasonEnum $finishReason = null,
        array $toolCallDeltas = []
    ) {
        $this->index = $index;
        $this->parts = $parts;
        $this->finishReason = $finishReason;
        $this->toolCallDeltas = $toolCallDeltas;
    }

    /**
     * Gets the candidate index this delta contributes to.
     *
     * @since n.e.x.t
     *
     * @return int The candidate index.
     */
    public function getIndex(): int
    {
        return $this->index;
    }

    /**
     * Gets the partial content parts.
     *
     * @since n.e.x.t
     *
     * @return list<MessagePart> The content parts.
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * Gets the finish reason.
     *
     * @since n.e.x.t
     *
     * @return FinishReasonEnum|null The finish reason, or null when not reported by this delta.
     */
    public function getFinishReason(): ?FinishReasonEnum
    {
        return $this->finishReason;
    }

    /**
     * Gets the partial tool calls.
     *
     * @since n.e.x.t
     *
     * @return list<ToolCallDelta> The tool call fragments, possibly empty.
     */
    public function getToolCallDeltas(): array
    {
        return $this->toolCallDeltas;
    }

    /**
     * Gets the delta text of this candidate's content channel.
     *
     * @since n.e.x.t
     *
     * @return string The content text delta, or an empty string when this delta carries none.
     */
    public function getDeltaText(): string
    {
        return $this->deltaTextForChannel(MessagePartChannelEnum::content());
    }

    /**
     * Gets the delta text of this candidate's reasoning (thought) channel.
     *
     * @since n.e.x.t
     *
     * @return string The reasoning text delta, or an empty string when this delta carries none.
     */
    public function getReasoningDeltaText(): string
    {
        return $this->deltaTextForChannel(MessagePartChannelEnum::thought());
    }

    /**
     * Concatenates the delta text of this candidate's parts on the given channel.
     *
     * @since n.e.x.t
     *
     * @param MessagePartChannelEnum $channel The channel to read.
     * @return string The concatenated delta text, or an empty string when there is none.
     */
    private function deltaTextForChannel(MessagePartChannelEnum $channel): string
    {
        $text = '';
        foreach ($this->parts as $part) {
            if ($part->getChannel()->is($channel) && $part->getText() !== null) {
                $text .= $part->getText();
            }
        }

        return $text;
    }
}
