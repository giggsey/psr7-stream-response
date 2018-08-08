<?php
/**
 *
 * User: giggsey
 * Date: 08/08/18
 * Time: 13:21
 */

namespace giggsey\PSR7StreamResponse;

use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PSR7StreamResponse extends Response
{
    /**
     * @var StreamInterface
     */
    protected $stream;
    protected $mimeType;
    protected $offset;
    protected $maxlen;

    public function __construct(
        StreamInterface $stream,
        $mimeType,
        $status = 200,
        $headers = array(),
        $public = true
    ) {
        parent::__construct(null, $status, $headers);

        $this->setStream($stream, $mimeType);

        if ($public) {
            $this->setPublic();
        }
    }

    /**
     * Sets the file to stream.
     *
     * @param StreamInterface $stream
     * @param string $mimeType
     *
     * @return $this
     */
    public function setStream(StreamInterface $stream, $mimeType)
    {
        $this->stream = $stream;
        $this->mimeType = $mimeType;

        return $this;
    }

    /**
     * @return StreamInterface
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Sets the Content-Disposition header with the given filename.
     *
     * @param string $disposition ResponseHeaderBag::DISPOSITION_INLINE or ResponseHeaderBag::DISPOSITION_ATTACHMENT
     * @param string $filename Use this UTF-8 encoded filename instead of the real name of the file
     *
     * @return $this
     */
    public function setContentDisposition($disposition, $filename)
    {
        $dispositionHeader = $this->headers->makeDisposition($disposition, $filename);
        $this->headers->set('Content-Disposition', $dispositionHeader);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare(Request $request)
    {
        $this->headers->set('Content-Length', $this->stream->getSize());

        if (!$this->headers->has('Accept-Ranges')) {
            // Only accept ranges on safe HTTP methods
            $this->headers->set('Accept-Ranges', $request->isMethodSafe(false) ? 'bytes' : 'none');
        }

        if (!$this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', $this->mimeType ?: 'application/octet-stream');
        }

        if ('HTTP/1.0' !== $request->server->get('SERVER_PROTOCOL')) {
            $this->setProtocolVersion('1.1');
        }

        $this->ensureIEOverSSLCompatibility($request);

        $this->offset = 0;
        $this->maxlen = -1;

        if ($request->headers->has('Range')) {
            // Process the range headers.
            if (!$request->headers->has('If-Range') || $this->hasValidIfRangeHeader($request->headers->get('If-Range'))) {
                $range = $request->headers->get('Range');
                $fileSize = $this->stream->getSize();

                list($start, $end) = explode('-', substr($range, 6), 2) + array(0);

                $end = ('' === $end) ? $fileSize - 1 : (int)$end;

                if ('' === $start) {
                    $start = $fileSize - $end;
                    $end = $fileSize - 1;
                } else {
                    $start = (int)$start;
                }

                if ($start <= $end) {
                    if ($start < 0 || $end > $fileSize - 1) {
                        $this->setStatusCode(416);
                        $this->headers->set('Content-Range', sprintf('bytes */%s', $fileSize));
                    } elseif (0 !== $start || $end !== $fileSize - 1) {
                        $this->maxlen = $end < $fileSize ? $end - $start + 1 : -1;
                        $this->offset = $start;

                        $this->setStatusCode(206);
                        $this->headers->set('Content-Range', sprintf('bytes %s-%s/%s', $start, $end, $fileSize));
                        $this->headers->set('Content-Length', $end - $start + 1);
                    }
                }
            }
        }

        return $this;
    }


    private function hasValidIfRangeHeader($header)
    {
        if ($this->getEtag() === $header) {
            return true;
        }

        if (null === $lastModified = $this->getLastModified()) {
            return false;
        }

        return $lastModified->format('D, d M Y H:i:s') . ' GMT' === $header;
    }

    /**
     * Sends the file.
     *
     * {@inheritdoc}
     */
    public function sendContent()
    {
        if (!$this->isSuccessful()) {
            return parent::sendContent();
        }

        if (0 === $this->maxlen) {
            return $this;
        }

        $this->stream->seek($this->offset);

        if ($this->maxlen === -1) {
            // Read the entire stream
            $this->maxlen = $this->stream->getSize() - $this->offset;
        }

        echo $this->stream->read($this->maxlen);

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \LogicException when the content is not null
     */
    public function setContent($content)
    {
        if (null !== $content) {
            throw new \LogicException('The content cannot be set on a PSR7StreamResponse instance.');
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return false
     */
    public function getContent()
    {
        return false;
    }
}
