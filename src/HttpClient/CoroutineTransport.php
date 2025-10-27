<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\HttpClient;

use Swoole\Coroutine\Http\Client as SwooleHttpClient;
use yii\httpclient\Exception;
use yii\httpclient\Request;
use yii\httpclient\Response;
use yii\httpclient\Transport;
use Yii;

class CoroutineTransport extends Transport
{
    public int $connectionTimeout = 3;

    public int $requestTimeout = 10;

    public bool $keepAlive = true;

    public function send($request): Response
    {
        $request->beforeSend();

        $parsedUrl = $this->parseUrl($request->getFullUrl());
        
        try {
            $client = new SwooleHttpClient(
                $parsedUrl['host'],
                $parsedUrl['port'],
                $parsedUrl['ssl']
            );
        } catch (\Throwable $e) {
            throw new Exception('Failed to create HTTP client: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $this->configureClient($client, $request);

        $token = $request->client->createRequestLogToken(
            $request->getMethod(),
            $request->getFullUrl(),
            $request->composeHeaderLines(),
            print_r($request->getContent(), true)
        );
        
        Yii::info($token, __METHOD__);
        Yii::beginProfile($token, __METHOD__);

        try {
            $response = $this->executeRequest($client, $request, $parsedUrl['path']);
        } catch (\Throwable $e) {
            Yii::endProfile($token, __METHOD__);
            throw new Exception('Request failed: ' . $e->getMessage(), $e->getCode(), $e);
        } finally {
            $client->close();
        }

        Yii::endProfile($token, __METHOD__);

        $request->afterSend($response);

        return $response;
    }

    public function batchSend(array $requests): array
    {
        if (\Swoole\Coroutine::getCid() < 0) {
            return parent::batchSend($requests);
        }

        $results = [];
        $channels = [];

        foreach ($requests as $key => $request) {
            $channels[$key] = new \Swoole\Coroutine\Channel(1);
            
            go(function () use ($request, $key, $channels) {
                try {
                    $response = $this->send($request);
                    $channels[$key]->push(['success' => true, 'response' => $response]);
                } catch (\Throwable $e) {
                    $channels[$key]->push(['success' => false, 'error' => $e]);
                }
            });
        }

        foreach ($channels as $key => $channel) {
            $result = $channel->pop();
            
            if (!$result['success']) {
                throw new Exception('Batch request failed: ' . $result['error']->getMessage(), 0, $result['error']);
            }
            
            $results[$key] = $result['response'];
        }

        return $results;
    }

    private function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        
        if ($parts === false || !isset($parts['host'])) {
            throw new Exception('Invalid URL: ' . $url);
        }

        $ssl = ($parts['scheme'] ?? 'http') === 'https';
        $port = $parts['port'] ?? ($ssl ? 443 : 80);
        $path = ($parts['path'] ?? '/') . 
                (isset($parts['query']) ? '?' . $parts['query'] : '') . 
                (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        return [
            'host' => $parts['host'],
            'port' => $port,
            'ssl' => $ssl,
            'path' => $path,
        ];
    }

    private function configureClient(SwooleHttpClient $client, Request $request): void
    {
        $client->set([
            'timeout' => $this->requestTimeout,
            'keep_alive' => $this->keepAlive,
        ]);

        $headers = [];
        $headerCollection = $request->getHeaders();
        
        foreach ($headerCollection->toArray() as $name => $values) {
            if (!is_array($values)) {
                $values = [$values];
            }
            // Join multiple header values with comma (per HTTP spec)
            $headers[$name] = implode(', ', $values);
        }

        if (!empty($headers)) {
            $client->setHeaders($headers);
        }

        $options = $request->getOptions();
        if (!empty($options)) {
            $this->applySwooleOptions($client, $options);
        }
    }

    private function applySwooleOptions(SwooleHttpClient $client, array $options): void
    {
        $swooleSettings = [];

        if (isset($options[CURLOPT_SSL_VERIFYPEER])) {
            $swooleSettings['ssl_verify_peer'] = (bool) $options[CURLOPT_SSL_VERIFYPEER];
        }

        if (isset($options[CURLOPT_SSL_VERIFYHOST])) {
            $swooleSettings['ssl_verify_host'] = (bool) $options[CURLOPT_SSL_VERIFYHOST];
        }

        if (isset($options[CURLOPT_CAINFO])) {
            $swooleSettings['ssl_cafile'] = $options[CURLOPT_CAINFO];
        }

        if (isset($options[CURLOPT_CAPATH])) {
            $swooleSettings['ssl_capath'] = $options[CURLOPT_CAPATH];
        }

        if (isset($options[CURLOPT_SSLCERT])) {
            $swooleSettings['ssl_cert_file'] = $options[CURLOPT_SSLCERT];
        }

        if (isset($options[CURLOPT_SSLKEY])) {
            $swooleSettings['ssl_key_file'] = $options[CURLOPT_SSLKEY];
        }

        if (!empty($swooleSettings)) {
            $client->set($swooleSettings);
        }
    }

    private function executeRequest(SwooleHttpClient $client, Request $request, string $path): Response
    {
        $method = strtoupper($request->getMethod());
        $content = $request->getContent();

        $success = match ($method) {
            'GET' => $client->get($path),
            'POST' => $client->post($path, $content),
            'PUT' => $client->setMethod('PUT') && $client->execute($path, $content),
            'DELETE' => $client->setMethod('DELETE') && $client->execute($path, $content),
            'PATCH' => $client->setMethod('PATCH') && $client->execute($path, $content),
            'HEAD' => $client->setMethod('HEAD') && $client->execute($path),
            'OPTIONS' => $client->setMethod('OPTIONS') && $client->execute($path),
            default => throw new Exception('Unsupported HTTP method: ' . $method),
        };

        if (!$success) {
            $errCode = $client->errCode;
            $errMsg = socket_strerror($errCode);
            throw new Exception("HTTP request failed: [{$errCode}] {$errMsg}");
        }

        $responseHeaders = [];
        
        // Add HTTP status line as first header (yii2-httpclient expects this format)
        $statusLine = sprintf(
            'HTTP/%s %d %s',
            '1.1',
            $client->statusCode,
            $this->getReasonPhrase($client->statusCode)
        );
        $responseHeaders[] = $statusLine;

        // Add response headers
        if (!empty($client->headers)) {
            foreach ($client->headers as $name => $value) {
                $responseHeaders[] = "{$name}: {$value}";
            }
        }

        // Add Set-Cookie headers
        if (!empty($client->set_cookie_headers)) {
            foreach ($client->set_cookie_headers as $cookie) {
                $responseHeaders[] = "Set-Cookie: {$cookie}";
            }
        }

        return $request->client->createResponse($client->body, $responseHeaders);
    }

    private function getReasonPhrase(int $statusCode): string
    {
        $phrases = [
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $phrases[$statusCode] ?? 'Unknown';
    }
}

