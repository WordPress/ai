<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\Streaming\ValueObjects;

/**
 * Represents a single decoded event from a Server-Sent Events stream.
 *
 * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
 *
 * @since n.e.x.t
 */
final class ServerSentEvent
{
    /**
     * @var string The event name. Defaults to "message" when the stream omits it.
     */
    private string $event;

    /**
     * @var string The event payload.
     */
    private string $data;

    /**
     * @var string The last event ID. Empty when the stream has not set one.
     */
    private string $id;

    /**
     * @var int|null The reconnection time in milliseconds, if the stream sent one.
     */
    private ?int $retry;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param string $event The event name.
     * @param string $data The event payload.
     * @param string $id The last event ID, or an empty string when none was set.
     * @param int|null $retry The reconnection time in milliseconds, or null.
     */
    public function __construct(string $event, string $data, string $id = '', ?int $retry = null)
    {
        $this->event = $event;
        $this->data = $data;
        $this->id = $id;
        $this->retry = $retry;
    }

    /**
     * Gets the event name.
     *
     * @since n.e.x.t
     *
     * @return string The event name ("message" when unspecified).
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    /**
     * Gets the event payload.
     *
     * @since n.e.x.t
     *
     * @return string The payload.
     */
    public function getData(): string
    {
        return $this->data;
    }

    /**
     * Gets the last event ID.
     *
     * @since n.e.x.t
     *
     * @return string The event ID, or an empty string when none was set.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gets the reconnection time in milliseconds, if the event set one.
     *
     * Parsed for spec completeness only. The SDK does not reconnect: provider
     * streams are one-shot and cannot be resumed, so this value is informational.
     *
     * @since n.e.x.t
     *
     * @return int|null The reconnection time, or null when none was set.
     */
    public function getRetry(): ?int
    {
        return $this->retry;
    }
}
