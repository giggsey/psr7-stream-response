<?php

use giggsey\PSR7StreamResponse\PSR7StreamResponse;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

covers(PSR7StreamResponse::class);

test('constructor initializes correctly', function () {
    $stream = Utils::streamFor('test data');
    $response = new PSR7StreamResponse($stream, 'application/pdf', 201, ['X-Custom' => 'Value']);

    expect($response->getStatusCode())->toBe(201)
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Custom'))->toBe('Value')
        ->and($response->getStream())->toBe($stream);
});

test('constructor handles public cache control', function () {
    $stream = Utils::streamFor('test');

    // Default is true
    $responsePublic = new PSR7StreamResponse($stream, 'text/plain');
    expect($responsePublic->headers->hasCacheControlDirective('public'))->toBeTrue();

    // Explicitly false
    $responsePrivate = new PSR7StreamResponse($stream, 'text/plain', 200, [], false);
    expect($responsePrivate->headers->hasCacheControlDirective('public'))->toBeFalse();
});

test('get and set stream', function () {
    $stream1 = Utils::streamFor('stream 1');
    $stream2 = Utils::streamFor('stream 2');

    $response = new PSR7StreamResponse($stream1, 'text/plain');
    expect($response->getStream())->toBe($stream1);

    $return = $response->setStream($stream2, 'application/octet-stream');
    expect($response->getStream())->toBe($stream2)
        ->and($return)->toBe($response);
});

test('set content disposition', function () {
    $stream = Utils::streamFor('test');
    $response = new PSR7StreamResponse($stream, 'application/pdf');

    $return = $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'download.pdf');

    expect($return)->toBe($response);
    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment')
        ->and($disposition)->toContain('filename=download.pdf');
});

test('prepare sets standard headers', function () {
    $content = '0123456789';
    $stream = Utils::streamFor($content);
    $response = new PSR7StreamResponse($stream, 'text/plain');

    $request = Request::create('/', 'GET');
    $response->prepare($request);

    expect($response->headers->get('Content-Length'))->toBe('10')
        ->and($response->headers->get('Content-Type'))->toBe('text/plain')
        ->and($response->headers->get('Accept-Ranges'))->toBe('bytes')
        ->and($response->getProtocolVersion())->toBe('1.1');
});

test('prepare does not override existing content type', function () {
    $stream = Utils::streamFor('data');
    $response = new PSR7StreamResponse($stream, 'application/pdf', 200, ['Content-Type' => 'text/plain']);

    $response->prepare(Request::create('/'));

    expect($response->headers->get('Content-Type'))->toBe('text/plain');
});

test('prepare sets accept ranges none for unsafe method', function () {
    $stream = Utils::streamFor('data');
    $response = new PSR7StreamResponse($stream, 'application/octet-stream');

    $request = Request::create('/', 'POST');
    $response->prepare($request);

    expect($response->headers->get('Accept-Ranges'))->toBe('none');
});

test('prepare with valid range request', function () {
    $string = '0123456789';
    $stream = Utils::streamFor($string);
    $response = new PSR7StreamResponse($stream, 'text/plain');

    $request = Request::create('/', 'GET');
    $request->headers->set('Range', 'bytes=2-6');

    $response->prepare($request);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('Content-Length'))->toBe('5')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 2-6/10');

    ob_start();
    $response->sendContent();
    $output = ob_get_clean();

    expect($output)->toBe('23456');
});

test('prepare with open ended range request', function () {
    $string = '0123456789';
    $stream = Utils::streamFor($string);
    $response = new PSR7StreamResponse($stream, 'text/plain');

    $request = Request::create('/', 'GET');
    $request->headers->set('Range', 'bytes=7-');

    $response->prepare($request);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('Content-Length'))->toBe('3')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 7-9/10');

    ob_start();
    $response->sendContent();
    $output = ob_get_clean();

    expect($output)->toBe('789');
});

test('prepare with unsatisfiable range request', function () {
    $string = '0123456789';
    $stream = Utils::streamFor($string);
    $response = new PSR7StreamResponse($stream, 'text/plain');

    $request = Request::create('/', 'GET');
    $request->headers->set('Range', 'bytes=15-20');

    $response->prepare($request);

    expect($response->getStatusCode())->toBe(416)
        ->and($response->headers->get('Content-Range'))->toBe('bytes */10');
});

test('prepare if range matching etag honours range', function () {
    $stream = Utils::streamFor('0123456789');
    $response = new PSR7StreamResponse($stream, 'text/plain');
    $response->setEtag('foo');

    $request = Request::create('/', 'GET');
    $request->headers->set('Range', 'bytes=2-5');
    $request->headers->set('If-Range', '"foo"');

    $response->prepare($request);

    expect($response->getStatusCode())->toBe(206);
});

test('prepare if range non matching etag ignores range', function () {
    $stream = Utils::streamFor('0123456789');
    $response = new PSR7StreamResponse($stream, 'text/plain');
    $response->setEtag('foo');

    $request = Request::create('/', 'GET');
    $request->headers->set('Range', 'bytes=2-5');
    $request->headers->set('If-Range', 'bar');

    $response->prepare($request);

    expect($response->getStatusCode())->toBe(200);
});

test('send content outputs full stream', function () {
    $string = 'Hello, World!';
    $stream = Utils::streamFor($string);
    $response = new PSR7StreamResponse($stream, 'text/plain');

    ob_start();
    $return = $response->sendContent();
    $output = ob_get_clean();

    expect($output)->toBe($string)
        ->and($return)->toBe($response);
});

test('set content throws when content is not null', function () {
    $stream = Utils::streamFor('test');
    $response = new PSR7StreamResponse($stream, 'text/plain');

    $response->setContent('trying to break it');
})->throws(LogicException::class, 'The content cannot be set on a PSR7StreamResponse instance.');

test('set content with null is allowed', function () {
    $stream = Utils::streamFor('test');
    $response = new PSR7StreamResponse($stream, 'text/plain');

    $return = $response->setContent(null);
    expect($return)->toBe($response);
});

test('get content returns false', function () {
    $stream = Utils::streamFor('test');
    $response = new PSR7StreamResponse($stream, 'text/plain');

    expect($response->getContent())->toBeFalse();
});

test('prepare with suffix range request', function () {
    $string = '0123456789';
    $stream = Utils::streamFor($string);
    $response = new PSR7StreamResponse($stream, 'text/plain');

    $request = Request::create('/', 'GET');
    $request->headers->set('Range', 'bytes=-3');

    $response->prepare($request);

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('Content-Length'))->toBe('3')
        ->and($response->headers->get('Content-Range'))->toBe('bytes 7-9/10');

    ob_start();
    $response->sendContent();
    $output = ob_get_clean();

    expect($output)->toBe('789');
});

test('prepare if range matching last modified honours range', function () {
    $stream = Utils::streamFor('0123456789');
    $response = new PSR7StreamResponse($stream, 'text/plain');
    $date = new \DateTime('yesterday');
    $response->setLastModified($date);

    $request = Request::create('/', 'GET');
    $request->headers->set('Range', 'bytes=2-5');
    $request->headers->set('If-Range', $date->format('D, d M Y H:i:s') . ' GMT');

    $response->prepare($request);

    expect($response->getStatusCode())->toBe(206);
});

test('send content with unsuccessful response', function () {
    $stream = Utils::streamFor('test');
    $response = new PSR7StreamResponse($stream, 'text/plain', 404);

    ob_start();
    $response->sendContent();
    $output = ob_get_clean();

    expect($output)->toBe('');
});

test('send content with zero max len', function () {
    $stream = Utils::streamFor('');
    $response = new PSR7StreamResponse($stream, 'text/plain');
    $response->prepare(Request::create('/'));

    ob_start();
    $response->sendContent();
    $output = ob_get_clean();
    expect($output)->toBe('');
});

test('prepare sets fallback content type', function () {
    $stream = Utils::streamFor('test');
    $response = new PSR7StreamResponse($stream, '');

    $response->headers->remove('Content-Type');

    $request = Request::create('/');
    $response->prepare($request);

    expect($response->headers->get('Content-Type'))->toBe('application/octet-stream');
});

test('send content outputs partial stream when offset is set', function () {
    $string = '0123456789';
    $stream = Utils::streamFor($string);
    $response = new PSR7StreamResponse($stream, 'text/plain');

    // Manually setting offset to check the mutation in sendContent
    // We can't set offset directly, but we can use a range request to set it
    $request = Request::create('/', 'GET');
    $request->headers->set('Range', 'bytes=3-');
    $response->prepare($request);

    ob_start();
    $response->sendContent();
    $output = ob_get_clean();

    expect($output)->toBe('3456789');
});
