# PSR-7 Stream Response

## Why?

Symfony's BinaryFileResponse allows presenting files to download to HTTP Clients. However, this expects full file paths.
Some projects may want to stream a PSR-7 Stream to the client instead.

## How to use

Instead of returning a BinaryFileResponse, create a PSR7StreamResponse, and return that.

### Before

```php
$response = new BinaryFileResponse($filePath);
$response = $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'my-file.mp3');

return $response;
```

### After

```php
$response = new PSR7StreamResponse($stream);
$response = $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'my-file.mp3');

return $response;
```
