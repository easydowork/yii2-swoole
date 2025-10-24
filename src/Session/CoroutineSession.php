<?php

declare(strict_types=1);

namespace Dacheng\Yii2\Swoole\Session;

use Dacheng\Yii2\Swoole\Redis\CoroutineRedisConnection;
use Swoole\Coroutine;
use Yii;
use yii\base\InvalidConfigException;
use yii\di\Instance;
use yii\redis\Session as YiiRedisSession;
use yii\web\Cookie;

class CoroutineSession extends YiiRedisSession
{
    public bool $autoCloseOnCoroutineEnd = true;

    private bool $deferRegistered = false;

    private int $deferCoroutineId = -1;
    
    private ?string $_sessionId = null;

    public function init()
    {
        $this->redis = Instance::ensure($this->redis, CoroutineRedisConnection::class);

        if (!$this->redis instanceof CoroutineRedisConnection) {
            throw new InvalidConfigException(sprintf(
                '%s requires redis component to be an instance of %s, %s given.',
                __CLASS__,
                CoroutineRedisConnection::class,
                is_object($this->redis) ? get_class($this->redis) : gettype($this->redis)
            ));
        }

        if ($this->keyPrefix === null) {
            $this->keyPrefix = substr(md5(Yii::$app->id), 0, 5);
        }

        parent::init();

        if ($this->getIsActive()) {
            Yii::warning('Session is already started', __METHOD__);
            $this->updateFlashCounters();
        }
    }

    public function open()
    {
        if ($this->getIsActive()) {
            return;
        }
        
        $this->isClosed = false;

        if ($this->autoCloseOnCoroutineEnd) {
            $this->registerCoroutineCloseHandler();
        }

        $this->ensureSessionId();

        // Load session data from Redis without calling parent::open()
        // to avoid session_set_save_handler() which fails after headers sent
        $data = $this->readSession($this->getId());
        
        // Unserialize session data
        if (!empty($data)) {
            $_SESSION = @unserialize($data) ?: [];
        } else {
            $_SESSION = [];
        }
        
        $this->updateFlashCounters();
        $this->ensureResponseCarriesSessionCookie();
    }

    /**
     * @var bool Track whether session has been closed
     */
    private bool $isClosed = false;

    public function close()
    {
        // Prevent duplicate close() calls
        if ($this->isClosed) {
            return;
        }
        
        // Save session data to Redis BEFORE marking as closed
        // This must happen before setting isClosed = true
        if (isset($_SESSION) && is_array($_SESSION) && !empty($_SESSION) && $this->_sessionId !== null) {
            try {
                // Serialize session data and write to Redis
                $data = serialize($_SESSION);
                $this->writeSession($this->getId(), $data);
            } catch (\Throwable $e) {
                \Yii::error('Failed to save session: ' . $e->getMessage(), __METHOD__);
            }
        }
        
        // NOW mark as closed
        $this->isClosed = true;

        if ($this->redis instanceof CoroutineRedisConnection) {
            $this->redis->close();
        }

        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION = [];
        }

        $this->deferRegistered = false;
        $this->deferCoroutineId = -1;
    }

    public function destroy()
    {
        if (!$this->getIsActive()) {
            return;
        }

        // Remove session data from Redis
        if ($this->_sessionId !== null) {
            try {
                $sessionKey = $this->calculateKey($this->getId());
                $this->redis->del($sessionKey);
            } catch (\Throwable $e) {
                \Yii::error('Failed to destroy session: ' . $e->getMessage(), __METHOD__);
            }
        }

        // Clear session data
        if (isset($_SESSION) && is_array($_SESSION)) {
            $_SESSION = [];
        }

        // Reset session ID
        $this->_sessionId = null;
        $this->isClosed = true;
    }

    public function reset(): void
    {
        $this->close();
    }
    
    /**
     * Override setId to avoid calling session_id() which doesn't work after headers sent
     */
    public function setId($value)
    {
        $this->_sessionId = $value;
    }
    
    /**
     * Override getId to avoid calling session_id() which doesn't work after headers sent
     */
    public function getId()
    {
        if ($this->_sessionId === null) {
            $this->_sessionId = session_create_id('');
        }
        return $this->_sessionId;
    }
    
    /**
     * Override getIsActive to check our internal state instead of PHP session state
     */
    public function getIsActive()
    {
        return !$this->isClosed && $this->_sessionId !== null;
    }

    private function registerCoroutineCloseHandler(): void
    {
        $cid = Coroutine::getCid();
        
        if ($cid < 0) {
            return;
        }

        if ($this->deferRegistered && $this->deferCoroutineId === $cid) {
            return;
        }

        $this->deferRegistered = true;
        $this->deferCoroutineId = $cid;

        Coroutine::defer(function (): void {
            $this->deferRegistered = false;
            $this->deferCoroutineId = -1;

            if ($this->getIsActive()) {
                $this->close();
            }
        });
    }

    private function ensureSessionId(): void
    {
        $name = $this->getName();
        $request = Yii::$app->getRequest();
        $cookieId = $request->getCookies()->getValue($name, '');

        if ($cookieId !== '') {
            if (session_status() !== PHP_SESSION_ACTIVE && session_id() !== $cookieId) {
                $this->setId($cookieId);
            }

            $this->setHasSessionId(true);

            return;
        }

        if ($this->getHasSessionId()) {
            return;
        }

        $newId = session_create_id('');
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $this->setId($newId);
        }

        $this->setHasSessionId(false);
    }

    private function ensureResponseCarriesSessionCookie(): void
    {
        if ($this->getUseCookies() === false) {
            return;
        }

        $response = Yii::$app->getResponse();
        $cookies = $response->getCookies();
        $name = $this->getName();
        $currentId = $this->getId();

        $existing = $cookies->get($name, false);
        if ($existing instanceof Cookie && (string) $existing->value === (string) $currentId) {
            return;
        }

        $params = $this->getCookieParams();

        $cookies->add(new Cookie([
            'name' => $name,
            'value' => $currentId,
            'domain' => $params['domain'] ?? '',
            'path' => $params['path'] ?? '/',
            'httpOnly' => $params['httponly'] ?? true,
            'secure' => $params['secure'] ?? false,
            'sameSite' => $params['samesite'] ?? null,
            'expire' => $params['lifetime'] === 0 ? 0 : (time() + (int) $params['lifetime']),
        ]));
    }
}
