<?php

namespace giggsey\PSR7StreamResponse\Tests;

use giggsey\PSR7StreamResponse\PSR7StreamResponse;
use GuzzleHttp\Psr7\Utils;
use LogicException;
use PHPUnit\Framework\TestCase;

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

    public function testStreamsWithOffsetAndMaxlen(): void
    {
        $string = '0123456789';
        $stream = Utils::streamFor($string);

        $response = new PSR7StreamResponse($stream);
        // Assuming you use these methods to handle ranges
        $response->setOffset(2)->setMaxlen(5);

        ob_start();
        $response->sendContent();
        $output = ob_get_clean();

        // Should start at index 2 and read 5 bytes
        $this->assertSame('23456', $output);
    }

    public function testSetsContentLengthHeaderAutomatically(): void
    {
        $string = '12345';
        $stream = Utils::streamFor($string);

        $response = new PSR7StreamResponse($stream);

        $this->assertTrue($response->headers->has('Content-Length'));
        $this->assertSame('5', $response->headers->get('Content-Length'));
    }

    public function testDoesNotOverwriteExistingContentLengthHeader(): void
    {
        $string = '12345';
        $stream = Utils::streamFor($string);

        $response = new PSR7StreamResponse($stream, 200, ['Content-Length' => '99']);

        $this->assertSame('99', $response->headers->get('Content-Length'));
    }

    public function testThrowsExceptionWhenSettingContent(): void
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
