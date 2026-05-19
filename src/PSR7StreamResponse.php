<?php

namespace giggsey\PSR7StreamResponse;

use LogicException;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Response;

class PSR7StreamResponse extends Response
{
    protected StreamInterface $stream;
    protected int $offset = 0;
    protected int $maxlen = -1;

    public function __construct(StreamInterface $stream, int $status = 200, array $headers = [])
    {
        parent::__construct(null, $status, $headers);
        $this->stream = $stream;

        // Automatically set Content-Length if the stream knows its size
        $size = $stream->getSize();
        if ($size !== null && !$this->headers->has('Content-Length')) {
            $this->headers->set('Content-Length', (string) $size);
        }

        // Indicate to the client that we accept Range requests
        if (!$this->headers->has('Accept-Ranges')) {
            $this->headers->set('Accept-Ranges', 'bytes');
        }
    }

    /**
     * Symfony 8 requires a static return type.
     */
    public function setContent(mixed $content): static
    {
        if (null !== $content) {
            throw new LogicException('Content cannot be set directly on a PSR7StreamResponse instance.');
        }

        return $this;
    }

    /**
     * Symfony 8 requires string|false return type.
     */
    public function getContent(): string|false
    {
        return false;
    }

    /**
     * Symfony 8 requires a static return type.
     */
    public function sendContent(): static
    {
        if (!$this->isSuccessful() && !$this->isRedirection()) {
            return parent::sendContent();
        }

        if ($this->stream->isSeekable()) {
            $this->stream->seek($this->offset);
        }

        $length = $this->maxlen;

        while (!$this->stream->eof()) {
            $readSize = $length !== -1 ? min(8192, $length) : 8192;
            $chunk = $this->stream->read($readSize);

            if ($chunk === '') {
                break;
            }

            echo $chunk;
            flush();

            if ($length !== -1) {
                $length -= strlen($chunk);
                if ($length <= 0) {
                    break;
                }
            }
        }

        $this->stream->close();

        return $this;
    }

    /**
     * Example fluent method for Range Handling (custom to your library).
     * Make sure all your fluent setters return `static` to remain compliant.
     */
    public function setOffset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    public function setMaxlen(int $maxlen): static
    {
        $this->maxlen = $maxlen;
        return $this;
    }
}
