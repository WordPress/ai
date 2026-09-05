<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\Streaming;

use WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface;
use WordPress\AiClient\Providers\Http\Streaming\Contracts\EventStreamParserInterface;
use WordPress\AiClient\Providers\Http\Streaming\ValueObjects\ServerSentEvent;

/**
 * Parses a Server-Sent Events (`text/event-stream`) response body.
 *
 * Supports the WHATWG event stream format, including `event`, `data`, `id`,
 * and `retry` fields, multi-line `data` values, comments, and all valid line
 * endings. Events are yielded as they arrive.
 *
 * Provider-specific messages such as OpenAI's `[DONE]` are returned unchanged
 * for the consumer to interpret. The stream is closed when parsing completes
 * or iteration stops.
 *
 * @since n.e.x.t
 */
final class SseEventStreamParser implements EventStreamParserInterface
{
    /**
     * Number of bytes to read from the stream per iteration.
     *
     * @see https://github.com/php/php-src/blob/e71b4e592864cfefe15f6861c6b477d89aec2f36/main/php_network.h#L252
     *
     * @var int
     */
    private const READ_CHUNK_BYTES = 8192;

    /**
     * The UTF-8 byte order mark.
     *
     * @var string
     */
    private const BOM = "\xEF\xBB\xBF";

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     *
     * @param StreamInterface $stream The response body stream.
     * @return \Generator<int, ServerSentEvent> The decoded events.
     */
    public function parse(StreamInterface $stream): iterable
    {
        $event = '';
        $data = '';
        $lastId = '';
        $retry = null;
        $hasData = false;

        try {
            foreach ($this->toLines($stream) as $line) {
                // A blank line ends the event.
                if ($line === '') {
                    if ($hasData) {
                        yield $this->createEvent($event, $data, $lastId, $retry);
                    }
                    $event = '';
                    $data = '';
                    $retry = null;
                    $hasData = false;
                    // The last ID persists across events, so it is not reset.
                    continue;
                }

                // Skip comment lines.
                if ($line[0] === ':') {
                    continue;
                }

                $colon = strpos($line, ':');
                if ($colon === false) {
                    $field = $line;
                    $value = '';
                } else {
                    $field = (string) substr($line, 0, $colon);
                    $value = (string) substr($line, $colon + 1);
                    // Strip one leading space from the value.
                    if (isset($value[0]) && $value[0] === ' ') {
                        $value = (string) substr($value, 1);
                    }
                }

                switch ($field) {
                    case 'event':
                        $event = $value;
                        break;
                    case 'data':
                        $data .= $value . "\n";
                        $hasData = true;
                        break;
                    case 'id':
                        // Ignore IDs that contain a NUL byte.
                        if (strpos($value, "\0") === false) {
                            $lastId = $value;
                        }
                        break;
                    case 'retry':
                        if ($value !== '' && ctype_digit($value)) {
                            $retry = (int) $value;
                        }
                        break;
                    default:
                        break;
                }
            }

            /*
             * Per the spec:
             *   Once the end of the file is reached, any pending data must be discarded. (If the file ends
             *   in the middle of an event, before the final empty line, the incomplete event is not dispatched.)
             *
             * @see https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
             */
        } finally {
            $stream->close();
        }
    }

    /**
     * Builds an event from the accumulated field state.
     *
     * @since n.e.x.t
     *
     * @param string $event The accumulated event name.
     * @param string $data The accumulated data buffer (newline-joined).
     * @param string $id The current last event ID.
     * @param int|null $retry The current reconnection time.
     * @return ServerSentEvent The event.
     */
    private function createEvent(string $event, string $data, string $id, ?int $retry): ServerSentEvent
    {
        return new ServerSentEvent(
            $event !== '' ? $event : 'message',
            (string) substr($data, 0, -1), // data always ends with a newline, so drop it.
            $id,
            $retry
        );
    }

    /**
     * Reads the stream and yields complete lines as they become available.
     *
     * Buffers partial lines across reads, strips a leading BOM, supports the
     * `\n`, `\r\n`, and `\r` terminators (including a `\r\n` split across reads),
     * and emits any trailing unterminated content once the stream ends.
     *
     * @since n.e.x.t
     *
     * @param StreamInterface $stream The response body stream.
     * @return \Generator<int, string> Complete lines, without terminators.
     */
    private function toLines(StreamInterface $stream): \Generator
    {
        $buffer = '';
        $bomChecked = false;

        while (!$stream->eof()) {
            $chunk = $stream->read(self::READ_CHUNK_BYTES);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;

            if (!$bomChecked) {
                if (strncmp($buffer, self::BOM, 3) === 0) {
                    $buffer = substr($buffer, 3);
                    $bomChecked = true;
                } elseif (strlen($buffer) >= 3) {
                    $bomChecked = true;
                } elseif ($buffer === substr(self::BOM, 0, strlen($buffer))) {
                    // Might be a partial BOM, wait for more bytes.
                    continue;
                } else {
                    $bomChecked = true;
                }
            }

            [$lines, $buffer] = $this->extractLines($buffer, false);
            foreach ($lines as $line) {
                yield $line;
            }
        }

        [$lines] = $this->extractLines($buffer, true);
        foreach ($lines as $line) {
            yield $line;
        }
    }

    /**
     * Splits a buffer into complete lines and the unconsumed remainder.
     *
     * When not at end of stream, a trailing lone `\r` is held back (it may be the
     * first half of a `\r\n` arriving next) along with any final unterminated
     * line. At end of stream, a trailing `\r` terminates a line and any remaining
     * content is emitted as a final line.
     *
     * @since n.e.x.t
     *
     * @param string $buffer The byte buffer.
     * @param bool $atEof Whether the stream has ended.
     * @return array{0: list<string>, 1: string} The complete lines and the remainder.
     */
    private function extractLines(string $buffer, bool $atEof): array
    {
        $lines = [];
        $len = strlen($buffer);
        $start = 0;
        $i = 0;

        while ($i < $len) {
            $c = $buffer[$i];

            if ($c === "\n") {
                $lines[] = (string) substr($buffer, $start, $i - $start);
                $i++;
                $start = $i;
            } elseif ($c === "\r") {
                if ($i + 1 < $len) {
                    $lines[] = (string) substr($buffer, $start, $i - $start);
                    $i += ($buffer[$i + 1] === "\n") ? 2 : 1;
                    $start = $i;
                } elseif ($atEof) {
                    $lines[] = (string) substr($buffer, $start, $i - $start);
                    $i++;
                    $start = $i;
                } else {
                    // A trailing CR might start a split CRLF, so hold it.
                    break;
                }
            } else {
                $i++;
            }
        }

        $remaining = (string) substr($buffer, $start);
        if ($atEof && $remaining !== '') {
            $lines[] = $remaining;
            $remaining = '';
        }

        return [$lines, $remaining];
    }
}
