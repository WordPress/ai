<?php

declare(strict_types=1);

namespace WordPress\AiClient\Results\ValueObjects;

/**
 * Represents a partial tool call carried by a streamed chunk.
 *
 * @todo Make this class readonly once php 8.2 is the minimum requirement.
 *
 * @since n.e.x.t
 */
final class ToolCallDelta
{
    /**
     * @var int|null The tool call slot index this fragment contributes to.
     */
    private ?int $index;

    /**
     * @var string|null The tool call id, when this fragment reports it.
     */
    private ?string $id;

    /**
     * @var string|null The function name, when this fragment reports it.
     */
    private ?string $functionName;

    /**
     * @var string The partial function arguments carried by this fragment.
     */
    private string $argumentsFragment;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param int|null $index The tool call slot index this fragment contributes to.
     * @param string|null $id The tool call id, when reported.
     * @param string|null $functionName The function name, when reported.
     * @param string $argumentsFragment The partial function arguments carried by this fragment.
     */
    public function __construct(
        ?int $index,
        ?string $id = null,
        ?string $functionName = null,
        string $argumentsFragment = ''
    ) {
        $this->index = $index;
        $this->id = $id;
        $this->functionName = $functionName;
        $this->argumentsFragment = $argumentsFragment;
    }

    /**
     * Gets the tool call slot index this fragment contributes to.
     *
     * @since n.e.x.t
     *
     * @return int|null The slot index, or null when not reported.
     */
    public function getIndex(): ?int
    {
        return $this->index;
    }

    /**
     * Gets the tool call id.
     *
     * @since n.e.x.t
     *
     * @return string|null The id, or null when not reported by this fragment.
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Gets the function name.
     *
     * @since n.e.x.t
     *
     * @return string|null The function name, or null when not reported by this fragment.
     */
    public function getFunctionName(): ?string
    {
        return $this->functionName;
    }

    /**
     * Gets the partial function arguments carried by this fragment.
     *
     * @since n.e.x.t
     *
     * @return string The arguments fragment (may be empty).
     */
    public function getArgumentsFragment(): string
    {
        return $this->argumentsFragment;
    }
}
