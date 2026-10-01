<?php

declare(strict_types=1);

namespace WordPress\AiClient\Results;

use Generator;
use Iterator;
use IteratorAggregate;
use Throwable;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\ValueObjects\GenerativeAiResultChunk;

/**
 * Represents a streamed result from a generative AI operation.
 *
 * @since n.e.x.t
 *
 * @implements IteratorAggregate<int, GenerativeAiResultChunk>
 */
final class StreamedGenerativeAiResult implements IteratorAggregate
{
    /**
     * @var Iterator<int, GenerativeAiResultChunk> The source chunk stream.
     */
    private Iterator $chunks;

    private ChunkAccumulator $accumulator;

    /**
     * @var list<callable(): void> Callbacks run once when consumption begins.
     */
    private array $startCallbacks = [];

    /**
     * @var list<callable(GenerativeAiResult): void> Callbacks run once when the result is assembled.
     */
    private array $completionCallbacks = [];

    /**
     * @var list<callable(Throwable): void> Callbacks run once when consumption fails.
     */
    private array $errorCallbacks = [];

    /**
     * @var bool Whether the source stream has been started.
     */
    private bool $started = false;

    /**
     * @var bool Whether the source stream has been fully read.
     */
    private bool $finished = false;

    /**
     * @var GenerativeAiResult|null The assembled result, once built.
     */
    private ?GenerativeAiResult $result = null;

    /**
     * @var Throwable|null The error that ended consumption, once it failed.
     */
    private ?Throwable $error = null;

    /**
     * Constructor.
     *
     * @since n.e.x.t
     *
     * @param Iterator<int, GenerativeAiResultChunk> $chunks The source chunk stream.
     * @param ProviderMetadata $providerMetadata Provider metadata for the assembled result.
     * @param ModelMetadata $modelMetadata Model metadata for the assembled result.
     */
    public function __construct(Iterator $chunks, ProviderMetadata $providerMetadata, ModelMetadata $modelMetadata)
    {
        $this->chunks = $chunks;
        $this->accumulator = new ChunkAccumulator($providerMetadata, $modelMetadata);
    }

    /**
     * Registers a callback to run once, when consumption begins.
     *
     * @since n.e.x.t
     *
     * @param callable(): void $callback The callback.
     * @return self
     */
    public function onStart(callable $callback): self
    {
        $this->startCallbacks[] = $callback;

        return $this;
    }

    /**
     * Registers a callback to run once, when the final result is first assembled.
     *
     * @since n.e.x.t
     *
     * @param callable(GenerativeAiResult): void $callback Receives the assembled result.
     * @return self
     */
    public function onComplete(callable $callback): self
    {
        $this->completionCallbacks[] = $callback;

        return $this;
    }

    /**
     * Registers a callback to run once, when consumption fails.
     *
     * @since n.e.x.t
     *
     * @param callable(Throwable): void $callback Receives the error.
     * @return self
     */
    public function onError(callable $callback): self
    {
        $this->errorCallbacks[] = $callback;

        return $this;
    }

    /**
     * Yields each chunk as it is read, folding it into the accumulated state.
     *
     * @since n.e.x.t
     *
     * @return Generator<int, GenerativeAiResultChunk> The chunks, in order.
     *
     * @throws RuntimeException If the source stream has already been consumed.
     */
    public function getIterator(): Generator
    {
        if ($this->started) {
            throw new RuntimeException(
                'This streamed result has already been consumed; the stream can be read only once.'
            );
        }

        try {
            while (true) {
                $chunk = $this->pull();
                if ($chunk === null) {
                    break;
                }
                yield $chunk;
            }

            $this->finalize();
        } catch (Throwable $e) {
            $this->fail($e);
            throw $e;
        }
    }

    /**
     * Returns the complete result, draining any unread chunks first.
     *
     * @since n.e.x.t
     *
     * @return GenerativeAiResult The assembled result.
     *
     * @throws Throwable The original stream error, if consumption failed.
     * @throws RuntimeException If the stream produced no candidates.
     */
    public function getFinalResult(): GenerativeAiResult
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        if ($this->result === null) {
            try {
                while ($this->pull() !== null) {
                    // Drain any remaining chunks so the result is complete.
                }

                $this->finalize();
            } catch (Throwable $e) {
                $this->fail($e);
                throw $e;
            }
        }

        if ($this->result === null) {
            $error = new RuntimeException('The stream produced no candidates.');
            $this->fail($error);
            throw $error;
        }

        return $this->result;
    }

    /**
     * Assembles the result and runs the completion callbacks.
     *
     * @since n.e.x.t
     *
     * @return void
     */
    private function finalize(): void
    {
        if (!$this->accumulator->hasCandidates()) {
            return;
        }

        $this->result = $this->accumulator->build();

        foreach ($this->completionCallbacks as $callback) {
            $callback($this->result);
        }
    }

    /**
     * Handles a failure in the stream, storing the error and running the error callbacks.
     *
     * @since n.e.x.t
     *
     * @param Throwable $error The error that ended consumption.
     * @return void
     */
    private function fail(Throwable $error): void
    {
        $this->error = $error;

        foreach ($this->errorCallbacks as $callback) {
            $callback($error);
        }
    }

    /**
     * Reads the next chunk from the source and folds it into the accumulated state.
     *
     * @since n.e.x.t
     *
     * @return GenerativeAiResultChunk|null The next chunk, or null when the stream is exhausted.
     */
    private function pull(): ?GenerativeAiResultChunk
    {
        if ($this->finished) {
            return null;
        }

        if (!$this->started) {
            $this->started = true;
            foreach ($this->startCallbacks as $callback) {
                $callback();
            }
            $this->chunks->rewind();
        } else {
            $this->chunks->next();
        }

        if (!$this->chunks->valid()) {
            $this->finished = true;
            return null;
        }

        $chunk = $this->chunks->current();
        $this->accumulator->add($chunk);

        return $chunk;
    }
}
