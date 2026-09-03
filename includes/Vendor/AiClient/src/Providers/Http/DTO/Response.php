<?php

declare(strict_types=1);

namespace WordPress\AiClient\Providers\Http\DTO;

use WordPress\AiClientDependencies\Nyholm\Psr7\Stream;
use WordPress\AiClientDependencies\Psr\Http\Message\StreamInterface;
use WordPress\AiClient\Common\AbstractDataTransferObject;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\Collections\HeadersCollection;

/**
 * Represents an HTTP response.
 *
 * This class encapsulates HTTP response data that has been converted
 * from PSR-7 responses by the HTTP transporter.
 *
 * @since 0.1.0
 *
 * @phpstan-type ResponseArrayShape array{
 *     statusCode: int,
 *     headers: array<string, list<string>>,
 *     body?: string|null
 * }
 *
 * @extends AbstractDataTransferObject<ResponseArrayShape>
 */
class Response extends AbstractDataTransferObject
{
    public const KEY_STATUS_CODE = 'statusCode';
    public const KEY_HEADERS = 'headers';
    public const KEY_BODY = 'body';

    /**
     * @var int The HTTP status code.
     */
    protected int $statusCode;

    /**
     * @var HeadersCollection The response headers.
     */
    protected HeadersCollection $headers;

    /**
     * @var string|null The response body as a string, once resolved.
     */
    protected ?string $body = null;

    /**
     * @var StreamInterface|null The response body stream, when the response is streamed.
     */
    protected ?StreamInterface $stream = null;

    /**
     * @var bool Whether the string body has been resolved from the stream.
     */
    private bool $bodyResolved;

    /**
     * Constructor.
     *
     * @since 0.1.0
     *
     * @param int $statusCode The HTTP status code.
     * @param array<string, string|list<string>> $headers The response headers.
     * @param string|StreamInterface|null $body The response body, as a string or a stream.
     *
     * @throws InvalidArgumentException If the status code is invalid.
     */
    public function __construct(int $statusCode, array $headers, $body = null)
    {
        if ($statusCode < 100 || $statusCode >= 600) {
            throw new InvalidArgumentException('Invalid HTTP status code: ' . $statusCode);
        }

        $this->statusCode = $statusCode;
        $this->headers = new HeadersCollection($headers);

        if ($body instanceof StreamInterface) {
            $this->stream = $body;
            $this->bodyResolved = false;
        } else {
            $this->body = $body;
            $this->bodyResolved = true;
        }
    }

    /**
     * Creates a copy of this response.
     *
     * Headers are cloned so the new response can modify them independently of
     * the original.
     *
     * The body stream is not cloned. Both responses share the same stream
     * instance, so consuming it from one response also consumes it from the other.
     *
     * @since 0.4.2
     */
    public function __clone()
    {
        // Clone headers collection
        $this->headers = clone $this->headers;
    }

    /**
     * Gets the HTTP status code.
     *
     * @since 0.1.0
     *
     * @return int The status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Gets the response headers.
     *
     * @since 0.1.0
     *
     * @return array<string, list<string>> The headers.
     */
    public function getHeaders(): array
    {
        return $this->headers->getAll();
    }

    /**
     * Gets a specific header value.
     *
     * @since 0.1.0
     *
     * @param string $name The header name (case-insensitive).
     * @return list<string>|null The header value(s) or null if not found.
     */
    public function getHeader(string $name): ?array
    {
        return $this->headers->get($name);
    }

    /**
     * Gets header values as a comma-separated string.
     *
     * @since 0.1.0
     *
     * @param string $name The header name (case-insensitive).
     * @return string|null The header values as a comma-separated string or null if not found.
     */
    public function getHeaderAsString(string $name): ?string
    {
        return $this->headers->getAsString($name);
    }

    /**
     * Gets the response body as a string.
     *
     * When the response is streamed, this reads the stream to completion, which
     * consumes it unless the stream is seekable.
     *
     * @since 0.1.0
     *
     * @return string|null The body, or null if empty.
     */
    public function getBody(): ?string
    {
        if (!$this->bodyResolved) {
            $this->bodyResolved = true;
            if ($this->stream !== null) {
                $contents = $this->readStream($this->stream);
                $this->body = $contents === '' ? null : $contents;
            }
        }

        return $this->body;
    }

    /**
     * Gets the response body as a PSR-7 stream.
     *
     * @since n.e.x.t
     *
     * @return StreamInterface The body stream.
     */
    public function getStream(): StreamInterface
    {
        if ($this->stream !== null) {
            return $this->stream;
        }

        return Stream::create($this->body ?? '');
    }

    /**
     * Checks if the response has a header.
     *
     * @since 0.1.0
     *
     * @param string $name The header name.
     * @return bool True if the header exists, false otherwise.
     */
    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    /**
     * Checks if the response indicates success.
     *
     * @since 0.1.0
     *
     * @return bool True if status code is 2xx, false otherwise.
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }


    /**
     * Gets the response data as an array.
     *
     * Attempts to decode the body as JSON. Returns null if the body
     * is empty or not valid JSON.
     *
     * @since 0.1.0
     *
     * @return array<string, mixed>|null The decoded data or null.
     */
    public function getData(): ?array
    {
        $body = $this->getBody();
        if ($body === null || $body === '') {
            return null;
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        /** @var array<string, mixed>|null $data */
        return is_array($data) ? $data : null;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public static function getJsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                self::KEY_STATUS_CODE => [
                    'type' => 'integer',
                    'minimum' => 100,
                    'maximum' => 599,
                    'description' => 'The HTTP status code.',
                ],
                self::KEY_HEADERS => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'description' => 'The response headers.',
                ],
                self::KEY_BODY => [
                    'type' => ['string', 'null'],
                    'description' => 'The response body.',
                ],
            ],
            'required' => [self::KEY_STATUS_CODE, self::KEY_HEADERS],
        ];
    }

    /**
     * {@inheritDoc}
     *
     * When the response is streamed, this reads the stream
     * to serialize the body.
     *
     * @since 0.1.0
     *
     * @return ResponseArrayShape
     */
    public function toArray(): array
    {
        $data = [
            self::KEY_STATUS_CODE => $this->statusCode,
            self::KEY_HEADERS => $this->headers->getAll(),
        ];

        $body = $this->getBody();
        if ($body !== null) {
            $data[self::KEY_BODY] = $body;
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public static function fromArray(array $array): self
    {
        static::validateFromArrayData($array, [
            self::KEY_STATUS_CODE,
            self::KEY_HEADERS,
        ]);

        return new self(
            $array[self::KEY_STATUS_CODE],
            $array[self::KEY_HEADERS],
            $array[self::KEY_BODY] ?? null
        );
    }

    /**
     * Reads a stream to a string, rewinding first when possible.
     *
     * @since n.e.x.t
     *
     * @param StreamInterface $stream The stream to read.
     * @return string The stream contents.
     */
    private function readStream(StreamInterface $stream): string
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $stream->getContents();
    }
}
