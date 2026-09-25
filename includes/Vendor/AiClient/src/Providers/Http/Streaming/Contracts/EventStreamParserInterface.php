<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\Streaming\Contracts;

use WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface;
use WordPress\AiClient\Providers\Http\Streaming\ValueObjects\ServerSentEvent;

/**
 * Decodes a response body stream into discrete events.
 *
 * @since n.e.x.t
 */
interface EventStreamParserInterface
{
    /**
     * Lazily decodes the given stream into events as they arrive.
     *
     * @since n.e.x.t
     *
     * @param StreamInterface $stream The response body stream.
     * @return iterable<ServerSentEvent> The decoded events.
     */
    public function parse(StreamInterface $stream): iterable;
}
