<?php

namespace giggsey\PSR7StreamResponse;

use LogicException;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class PSR7StreamResponse extends Response
{
    protected StreamInterface $stream;
    protected int $offset = 0;
    protected int $maxlen = -1;

    public function __construct(StreamInterface $stream, int $status = 200, array $headers = [], bool $public = true)
    {
        parent::__construct(null, $status, $headers);
        $this->stream = $stream;

        if ($public) {
            $this->setPublic();
        }

        if (!$this->headers->has('Accept-Ranges')) {
            $this->headers->set('Accept-Ranges', 'bytes');
        }

        $size = $stream->getSize();
        if ($size !== null && !$this->headers->has('Content-Length')) {
            $this->headers->set('Content-Length', (string) $size);
        }
    }

    public function getStream(): StreamInterface
    {
        return $this->stream;
    }

    /**
     * @return static
     */
    public function setStream(StreamInterface $stream): static
    {
        $this->stream = $stream;
        return $this;
    }

    /**
     * @return static
     */
    public function setContentDisposition(string $disposition, string $filename = '', string $filenameFallback = ''): static
    {
        if ($filename === '') {
            $filename = $filenameFallback;
        }

        $dispositionHeader = $this->headers->makeDisposition($disposition, $filename, $filenameFallback);
        $this->headers->set('Content-Disposition', $dispositionHeader);

        return $this;
    }

    /**
     * Parses Range requests and sets the offset/maxlen before sending.
     * * @return static
     */
    public function prepare(Request $request): static
    {
        if (!$this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', 'application/octet-stream');
        }

        $size = $this->stream->getSize();
        $this->offset = 0;
        $this->maxlen = -1;

        if ($size !== null && $this->isSuccessful() && $request->headers->has('Range') && $this->stream->isSeekable()) {
            $range = $request->headers->get('Range');

            if (preg_match('/^bytes=(\d+)-(\d*)$/', $range, $matches)) {
                $start = (int) $matches[1];
                $end = $matches[2] !== '' ? (int) $matches[2] : $size - 1;

                if ($start <= $end && $start < $size) {
                    $this->setStatusCode(206); // Partial Content
                    $this->headers->set('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size));
                    $this->headers->set('Content-Length', (string) ($end - $start + 1));
                    $this->offset = $start;
                    $this->maxlen = $end - $start + 1;
                } else {
                    $this->setStatusCode(416); // Range Not Satisfiable
                    $this->headers->set('Content-Range', sprintf('bytes */%d', $size));
                }
            }
        }

        return parent::prepare($request);
    }

    /**
     * @return static
     */
    public function setContent(mixed $content): static
    {
        if (null !== $content) {
            throw new LogicException('Content cannot be set directly on a PSR7StreamResponse instance.');
        }

        return $this;
    }

    public function getContent(): string|false
    {
        return false;
    }

    /**
     * @return static
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
}
