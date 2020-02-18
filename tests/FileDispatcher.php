<?php
declare(strict_types = 1);

namespace Embed\Tests;

use Embed\Http\CurlDispatcher;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\VarExporter\VarExporter;

/**
 * Decorator to cache requests into files
 */
final class FileDispatcher implements ClientInterface
{
    private int $mode = 0;
    private string $path;
    private ResponseFactoryInterface $responseFactory;
    private ClientInterface $client;

    public function __construct(string $path, ResponseFactoryInterface $responseFactory, ClientInterface $client = null)
    {
        $this->path = $path;
        $this->responseFactory = $responseFactory;
        $this->client = $client ?: new CurlDispatcher($responseFactory);
    }

    public function setMode(int $mode): void
    {
        $this->mode = $mode;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $filename = $this->path.'/'.self::getFilename($request->getUri());

        if ($this->mode === 0 && is_file($filename)) {
            $response = $this->readResponse($filename);
        } else {
            $response = $this->client->sendRequest($request);
        }

        if ($this->mode === 2) {
            $this->saveResponse($response, $filename);
        }

        return $response;
    }

    public static function getFilename(UriInterface $uri): string
    {
        $query = $uri->getQuery();

        return sprintf(
            '%s.%s%s.php',
            $uri->getHost(),
            trim(preg_replace('/[^\w.-]+/', '-', strtolower($uri->getPath())), '-'),
            $query ? '.'.md5($uri->getQuery()) : ''
        );
    }

    private function readResponse(string $filename): ResponseInterface
    {
        $message = require $filename;
        $response = $this->responseFactory->createResponse($message['statusCode'], $message['reasonPhrase']);

        foreach ($message['headers'] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $body = $response->getBody();
        $body->write($message['body']);
        $body->rewind();

        return $response;
    }

    private function saveResponse(ResponseInterface $response, string $filename): void
    {
        $message = [
            'headers' => $response->getHeaders(),
            'statusCode' => $response->getStatusCode(),
            'reasonPhrase' => $response->getReasonPhrase(),
            'body' => (string) $response->getBody(),
        ];

        file_put_contents(
            $filename,
            sprintf("<?php\ndeclare(strict_types = 1);\n\nreturn %s;\n", VarExporter::export($message))
        );
    }
}
