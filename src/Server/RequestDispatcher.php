<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Server;

use Dacheng\Yii2\Swoole\Coroutine\CoroutineApplication;
use Dacheng\Yii2\Swoole\Db\CoroutineConnection;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Throwable;
use Yii;
use yii\base\BaseObject;
use yii\base\ErrorHandler;
use yii\base\InvalidConfigException;
use yii\log\Logger;
use yii\web\Application;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;
use yii\web\Request as YiiRequest;
use yii\web\Response as YiiResponse;
use yii\web\ResponseFormatterInterface;

class RequestDispatcher extends BaseObject implements RequestDispatcherInterface
{
    public ?string $appConfig = null;

    private ?Application $application = null;

    private ?array $applicationConfig = null;

    private ?string $applicationClass = null;

    private ?string $entryScript = null;

    public function __construct(?string $appConfig = null, array $config = [])
    {
        if ($appConfig !== null) {
            $config['appConfig'] = $appConfig;
        }

        parent::__construct($config);
    }

    public function init(): void
    {
        parent::init();

        if ($this->appConfig === null) {
            throw new InvalidConfigException('Property "appConfig" must be configured with a Yii web application config file.');
        }
    }

    public function dispatch(Request $request, Response $response, SwooleHttpServer $server): void
    {
        $previousApp = Yii::$app instanceof Application ? Yii::$app : null;
        $app = $this->getApplication();
        Yii::$app = $app;

        $restoreGlobals = $this->applySwooleGlobals($request, $app);

        $yiiRequest = $app->getRequest();
        if (!$yiiRequest instanceof YiiRequest) {
            throw new InvalidConfigException('Application "request" component must be an instance of yii\\web\\Request.');
        }

        $this->populateRequest($app, $yiiRequest, $request);

        $yiiResponse = $app->getResponse();
        $yiiResponse->clear();
        $yiiResponse->format = YiiResponse::FORMAT_HTML;
        $this->prepareLogger($app);

        try {
            $handledResponse = $app->handleRequest($yiiRequest);
            $this->finalizeResponse($response, $handledResponse);
        } catch (Throwable $exception) {
            if (!$this->handleExceptionWithYii($exception, $app, $response)) {
                Yii::error($exception, __METHOD__);

                if ($this->isWritable($response)) {
                    $response->status(500);
                    $response->header('Content-Type', 'text/plain; charset=UTF-8');
                    $body = defined('YII_DEBUG') && YII_DEBUG ? (string) $exception : 'Internal Server Error';
                    $response->end($body);
                }
            }
        } finally {
            $app->params['__swooleRequest'] = null;
            $yiiResponse->clear();
            $yiiResponse->format = YiiResponse::FORMAT_HTML;
            $this->flushLogger($app);

            $restoreGlobals();

            if ($app instanceof CoroutineApplication) {
                $app->resetCoroutineContext();
            }

            $this->restorePreviousApplication($previousApp);
        }
    }

    private function handleExceptionWithYii(Throwable $exception, Application $app, Response $swooleResponse): bool
    {
        try {
            if (!$app->has('errorHandler')) {
                return false;
            }

            /** @var ErrorHandler|null $errorHandler */
            $errorHandler = $app->getErrorHandler();
            if ($errorHandler === null) {
                return false;
            }

            $errorHandler->exception = $exception;
            $errorHandler->logException($exception);

            $bufferLevel = ob_get_level();
            ob_start();
            try {
                $this->invokeErrorHandlerRenderException($errorHandler, $exception);
                $bufferedOutput = ob_get_contents() ?: '';
            } finally {
                while (ob_get_level() > $bufferLevel) {
                    ob_end_clean();
                }

                $errorHandler->exception = null;
            }

            $yiiResponse = $app->getResponse();
            if ($yiiResponse->isSent) {
                $yiiResponse->isSent = false;
            }

            if ($yiiResponse->content === null) {
                $yiiResponse->content = $bufferedOutput;
            }

            if ($this->isWritable($swooleResponse)) {
                $this->finalizeResponse($swooleResponse, $yiiResponse);
            }

            return true;
        } catch (Throwable $handlerException) {
            Yii::error($handlerException, __METHOD__ . '::errorHandler');

            return false;
        }
    }

    private function invokeErrorHandlerRenderException(ErrorHandler $errorHandler, Throwable $exception): void
    {
        $method = (new \ReflectionObject($errorHandler))->getMethod('renderException');
        $method->setAccessible(true);
        $method->invoke($errorHandler, $exception);
    }

    private function prepareLogger(Application $app): void
    {
        if (!$app->has('log')) {
            return;
        }

        $log = $app->getLog();
        $logger = $log->getLogger();

        if ($logger instanceof Logger) {
            $logger->flush();
        }
    }

    private function flushLogger(Application $app): void
    {
        if (!$app->has('log')) {
            return;
        }

        $log = $app->getLog();
        $logger = $log->getLogger();

        if ($logger instanceof Logger) {
            $logger->flush(true);
        }
    }

    private function getApplication(): Application
    {
        if ($this->application !== null) {
            return $this->application;
        }

        [$class, $config] = $this->loadApplicationConfig();

        /** @var class-string<Application> $class */
        $this->application = new $class($config);

        return $this->application;
    }

    /**
     * @return array{0: class-string<Application>, 1: array<string, mixed>}
     */
    private function loadApplicationConfig(): array
    {
        if ($this->applicationConfig !== null && $this->applicationClass !== null) {
            return [$this->applicationClass, $this->applicationConfig];
        }

        $config = require $this->appConfig;

        if ($config instanceof Application) {
            throw new InvalidConfigException('Application config must return an array, not an instance of yii\\web\\Application.');
        }

        if (!is_array($config)) {
            throw new InvalidConfigException('Application config must return an array or configure yii\\web\\Application.');
        }

        $class = $config['class'] ?? CoroutineApplication::class;

        if (!is_string($class) || !is_a($class, Application::class, true)) {
            throw new InvalidConfigException(sprintf('Application class "%s" must be a subclass of %s.', (string) $class, Application::class));
        }

        unset($config['class']);

        $this->applicationClass = $class;
        $this->applicationConfig = $config;

        /** @var class-string<Application> $class */
        return [$class, $config];
    }

    private function populateRequest(Application $app, YiiRequest $yiiRequest, Request $swooleRequest): void
    {
        $server = $swooleRequest->server ?? [];
        $headers = $swooleRequest->header ?? [];

        $method = strtoupper($server['request_method'] ?? 'GET');
        $uri = $server['request_uri'] ?? '/';
        $queryString = $server['query_string'] ?? '';
        $scheme = $headers['x-forwarded-proto'] ?? (!empty($server['https']) && $server['https'] !== 'off' ? 'https' : 'http');
        $hostHeader = $headers['host'] ?? ($server['server_name'] ?? '127.0.0.1');

        $fullUrl = $queryString === '' ? $uri : $uri . '?' . $queryString;
        $scriptUrl = '/index.php';
        $baseUrl = '';

        $pathInfo = $uri;
        if (($pos = strpos($pathInfo, '?')) !== false) {
            $pathInfo = substr($pathInfo, 0, $pos);
        }
        $pathInfo = ltrim($pathInfo, '/');

        $yiiRequest->setQueryParams($swooleRequest->get ?? []);
        $yiiRequest->setBodyParams($swooleRequest->post ?? []);
        $yiiRequest->setRawBody($swooleRequest->rawContent() ?: '');
        if (method_exists($yiiRequest, 'setMethod')) {
            $yiiRequest->setMethod($method);
        }

        $yiiRequest->setHostInfo(sprintf('%s://%s', $scheme, $hostHeader));
        $yiiRequest->setUrl($fullUrl);
        $yiiRequest->setScriptUrl($scriptUrl);
        $yiiRequest->setBaseUrl($baseUrl);
        $yiiRequest->setPathInfo($pathInfo);

        $headerCollection = $yiiRequest->getHeaders();
        if ($headerCollection instanceof HeaderCollection) {
            $headerCollection->removeAll();
            foreach ($headers as $name => $value) {
                $headerCollection->set($name, $value);
            }
        }

        if (!empty($swooleRequest->cookie)) {
            $cookieCollection = $yiiRequest->getCookies();
            if ($cookieCollection instanceof CookieCollection) {
                $originalReadOnly = $cookieCollection->readOnly;
                $cookieCollection->readOnly = false;
                $cookieCollection->removeAll();

                foreach ($swooleRequest->cookie as $name => $value) {
                    $cookieCollection->add(new Cookie([
                        'name' => $name,
                        'value' => $value,
                    ]));
                }

                $cookieCollection->readOnly = $originalReadOnly;
            }
        }

        if (!empty($swooleRequest->files)) {
            $yiiRequest->setBodyParams(array_merge($yiiRequest->getBodyParams(), $swooleRequest->files));
        }

        $app->params['__swooleRequest'] = $swooleRequest;
    }

    private function applySwooleGlobals(Request $request, Application $app): callable
    {
        $original = [
            '_SERVER' => $_SERVER,
            '_GET' => $_GET,
            '_POST' => $_POST,
            '_FILES' => $_FILES,
            '_COOKIE' => $_COOKIE,
            '_REQUEST' => $_REQUEST,
        ];

        $server = [];
        foreach ($request->server ?? [] as $key => $value) {
            $server[strtoupper($key)] = $value;
        }

        foreach ($request->header ?? [] as $key => $value) {
            $normalized = strtoupper(str_replace('-', '_', $key));
            if ($normalized === 'CONTENT_TYPE' || $normalized === 'CONTENT_LENGTH') {
                $server[$normalized] = $value;
            } else {
                $server['HTTP_' . $normalized] = $value;
            }
        }

        $scriptFile = $this->getEntryScriptPath($app);

        $server['REQUEST_METHOD'] = $server['REQUEST_METHOD'] ?? 'GET';
        $server['REQUEST_URI'] = $server['REQUEST_URI'] ?? '/';
        if (!isset($server['QUERY_STRING'])) {
            $server['QUERY_STRING'] = $request->server['query_string'] ?? http_build_query($request->get ?? []);
        }
        $server['REMOTE_ADDR'] = $server['REMOTE_ADDR'] ?? ($request->server['remote_addr'] ?? '127.0.0.1');
        $server['REMOTE_PORT'] = $server['REMOTE_PORT'] ?? ($request->server['remote_port'] ?? 0);
        $server['SERVER_PROTOCOL'] = $server['SERVER_PROTOCOL'] ?? ($request->server['server_protocol'] ?? 'HTTP/1.1');
        $server['SERVER_NAME'] = $server['SERVER_NAME'] ?? ($request->header['host'] ?? 'localhost');
        $server['SERVER_PORT'] = $server['SERVER_PORT'] ?? ($request->server['server_port'] ?? 80);
        $server['SCRIPT_FILENAME'] = $server['SCRIPT_FILENAME'] ?? $scriptFile;
        $server['SCRIPT_NAME'] = $server['SCRIPT_NAME'] ?? '/index.php';
        $server['PHP_SELF'] = $server['PHP_SELF'] ?? $server['SCRIPT_NAME'];
        $server['DOCUMENT_ROOT'] = $server['DOCUMENT_ROOT'] ?? dirname($scriptFile);

        $_SERVER = $server;
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];
        $_FILES = $request->files ?? [];
        $_COOKIE = $request->cookie ?? [];
        $_REQUEST = array_merge($_GET, $_POST);

        return static function () use ($original): void {
            $_SERVER = $original['_SERVER'];
            $_GET = $original['_GET'];
            $_POST = $original['_POST'];
            $_FILES = $original['_FILES'];
            $_COOKIE = $original['_COOKIE'];
            $_REQUEST = $original['_REQUEST'];
        };
    }

    private function finalizeResponse(Response $response, YiiResponse $yiiResponse): void
    {
        if (!$this->isWritable($response)) {
            return;
        }

        if (!$yiiResponse->isSent) {
            $yiiResponse->trigger(YiiResponse::EVENT_BEFORE_SEND);
            $this->prepareResponse($yiiResponse);
        }

        $response->status($yiiResponse->getStatusCode());

        foreach ($yiiResponse->getHeaders()->toArray() as $name => $values) {
            foreach ((array) $values as $value) {
                $response->header($name, (string) $value);
            }
        }

        foreach ($yiiResponse->cookies as $cookie) {
            if (!$cookie instanceof Cookie) {
                continue;
            }

            $response->cookie(
                $cookie->name,
                (string) $cookie->value,
                $cookie->expire,
                $cookie->path,
                $cookie->domain,
                $cookie->secure,
                $cookie->httpOnly,
                $cookie->sameSite ?? ''
            );
        }

        $bufferLevel = ob_get_level();
        ob_start();
        $body = '';

        try {
            $this->invokeResponseSendContent($yiiResponse);
            $body = ob_get_contents() ?: '';
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
        }

        $response->end($body);

        if (!$yiiResponse->isSent) {
            $yiiResponse->trigger(YiiResponse::EVENT_AFTER_SEND);
            $yiiResponse->isSent = true;
        }
    }

    private function isWritable(Response $response): bool
    {
        return !method_exists($response, 'isWritable') || $response->isWritable();
    }

    private function getEntryScriptPath(Application $app): string
    {
        if ($this->entryScript !== null) {
            return $this->entryScript;
        }

        $candidates = [
            Yii::getAlias('@app/web/index.php', false),
            Yii::getAlias('@webroot/index.php', false),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_file($candidate)) {
                return $this->entryScript = realpath($candidate) ?: $candidate;
            }
        }

        return $this->entryScript = $app->getBasePath() . '/web/index.php';
    }

    private function prepareResponse(YiiResponse $response): void
    {
        if ($response->format === YiiResponse::FORMAT_RAW) {
            if ($response->content === null && $response->data !== null) {
                $response->content = $response->data;
            }

            return;
        }

        $formatter = $response->formatters[$response->format] ?? null;
        if ($formatter === null && isset($response->formatters['default'])) {
            $formatter = $response->formatters['default'];
        }

        if ($formatter !== null) {
            if (!$formatter instanceof ResponseFormatterInterface) {
                $formatter = Yii::createObject($formatter);
            }

            if (!$formatter instanceof ResponseFormatterInterface) {
                throw new InvalidConfigException('Invalid response formatter for format: ' . $response->format);
            }

            $formatter->format($response);

            return;
        }

        if ($response->format === YiiResponse::FORMAT_HTML) {
            if ($response->content === null && $response->data !== null) {
                $response->content = (string) $response->data;
            }

            return;
        }

        throw new InvalidConfigException('Unsupported response format: ' . $response->format);
    }

    private function invokeResponseSendContent(YiiResponse $response): void
    {
        $method = (new \ReflectionObject($response))->getMethod('sendContent');
        $method->setAccessible(true);
        $method->invoke($response);
    }

    private function restorePreviousApplication(?Application $previousApp): void
    {
        if ($previousApp !== null) {
            Yii::$app = $previousApp;

            return;
        }

        Yii::$app = null;
    }
}

