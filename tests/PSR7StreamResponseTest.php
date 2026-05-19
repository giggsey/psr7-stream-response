<?php

namespace giggsey\PSR7StreamResponse\Tests;

use giggsey\PSR7StreamResponse\PSR7StreamResponse;
use GuzzleHttp\Psr7\Utils;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class PSR7StreamResponseTest extends TestCase
{
    public function testStreamsEntireContentCorrectly(): void
    {
        $string = 'Hello, Symfony 8!';
        $stream = Utils::streamFor($string);

        $response = new PSR7StreamResponse($stream);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertSame($string, $output);
    }

    public function testSetsContentLengthAndAcceptRangesHeadersAutomatically(): void
    {
        $string = '12345';
        $stream = Utils::streamFor($string);

        $response = new PSR7StreamResponse($stream);

        $this->assertTrue($response->headers->has('Content-Length'));
        $this->assertSame('5', $response->headers->get('Content-Length'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    public function testHandlesPublicCacheControlCorrectly(): void
    {
        $stream = Utils::streamFor('test');

        // Default is true
        $responsePublic = new PSR7StreamResponse($stream);
        $this->assertTrue($responsePublic->headers->hasCacheControlDirective('public'));

        // Explicitly false
        $responsePrivate = new PSR7StreamResponse($stream, 200, [], false);
        $this->assertFalse($responsePrivate->headers->hasCacheControlDirective('public'));
    }

    public function testGetAndSetStream(): void
    {
        $stream1 = Utils::streamFor('stream 1');
        $stream2 = Utils::streamFor('stream 2');

        $response = new PSR7StreamResponse($stream1);
        $this->assertSame($stream1, $response->getStream());

        $response->setStream($stream2);
        $this->assertSame($stream2, $response->getStream());
    }

    public function testSetContentDisposition(): void
    {
        $stream = Utils::streamFor('test');
        $response = new PSR7StreamResponse($stream);

        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'download.pdf');

        $disposition = $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('attachment', $disposition);
        // Removed the double quotes around the filename to match Symfony's native output
        $this->assertStringContainsString('filename=download.pdf', $disposition);
    }

    public function testPrepareWithValidRangeRequest(): void
    {
        $string = '0123456789';
        $stream = Utils::streamFor($string);
        $response = new PSR7StreamResponse($stream);

        $request = Request::create('/', 'GET');
        $request->headers->set('Range', 'bytes=2-6');

        $response->prepare($request);

        // Assert Headers & Status
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('5', $response->headers->get('Content-Length'));
        $this->assertSame('bytes 2-6/10', $response->headers->get('Content-Range'));

        // Assert Stream Output
        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        // Should read bytes at index 2, 3, 4, 5, 6
        $this->assertSame('23456', $output);
    }

    public function testPrepareWithOpenEndedRangeRequest(): void
    {
        $string = '0123456789';
        $stream = Utils::streamFor($string);
        $response = new PSR7StreamResponse($stream);

        $request = Request::create('/', 'GET');
        $request->headers->set('Range', 'bytes=7-'); // Read from index 7 to the end

        $response->prepare($request);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('3', $response->headers->get('Content-Length'));
        $this->assertSame('bytes 7-9/10', $response->headers->get('Content-Range'));

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        $this->assertSame('789', $output);
    }

    public function testPrepareWithUnsatisfiableRangeRequest(): void
    {
        $string = '0123456789';
        $stream = Utils::streamFor($string);
        $response = new PSR7StreamResponse($stream);

        $request = Request::create('/', 'GET');
        $request->headers->set('Range', 'bytes=15-20'); // Out of bounds

        $response->prepare($request);

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */10', $response->headers->get('Content-Range'));
    }

    public function testThrowsExceptionWhenSettingContentDirectly(): void
    {
        $stream = Utils::streamFor('test');
        $response = new PSR7StreamResponse($stream);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Content cannot be set directly on a PSR7StreamResponse instance.');

        $response->setContent('trying to break it');
    }

    public function testGetContentReturnsFalse(): void
    {
        $stream = Utils::streamFor('test');
        $response = new PSR7StreamResponse($stream);

        $this->assertFalse($response->getContent());
    }
}
