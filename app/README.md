# Yii2 Swoole Example Application

This is a minimal example application demonstrating the yii2-swoole extension.

## Setup

1. Make sure you have Swoole 6+ installed:
```bash
pecl install swoole
```

2. Make the console script executable:
```bash
chmod +x yii
```

## Running the Server

Start the Swoole HTTP server:
```bash
./yii swoole/start
```

The server will start on `http://0.0.0.0:9501`

## Testing Endpoints

### Basic Test
```bash
curl http://localhost:9501/
```

### Request Information
```bash
curl http://localhost:9501/site/test
```

### Query Parameters
```bash
curl "http://localhost:9501/site/test?foo=bar&baz=qux"
```

### POST Request
```bash
curl -X POST http://localhost:9501/site/test -d "key=value"
```

### Set Cookie
```bash
curl -c cookies.txt http://localhost:9501/site/set-cookie
```

### Get Cookie
```bash
curl -b cookies.txt http://localhost:9501/site/get-cookie
```

### Coroutine Sleep Test
```bash
# This will sleep for 2 seconds using Swoole coroutine (non-blocking)
curl "http://localhost:9501/site/sleep?seconds=2"
```

### Test Multiple Concurrent Requests
```bash
# Run multiple requests simultaneously to test coroutine performance
for i in {1..5}; do
  curl "http://localhost:9501/site/sleep?seconds=2" &
done
wait
```

## Stopping the Server

From another terminal:
```bash
./yii swoole/stop
```

Or press `Ctrl+C` in the terminal where the server is running.

## Configuration

- **Web config**: `config/web.php`
- **Console config**: `config/console.php`
- **Parameters**: `config/params.php`

## Features Demonstrated

1. **Basic routing** - URL routing with Yii2's URL manager
2. **Request handling** - GET, POST, headers, query parameters
3. **Cookie management** - Setting and reading cookies
4. **Coroutine support** - Non-blocking sleep operations
5. **JSON responses** - API-style responses
6. **Error handling** - Proper error responses

## Notes

- The server runs in coroutine mode, allowing concurrent request handling
- Each request is isolated with proper state management
- The Swoole request object is available via `Yii::$app->params['__swooleRequest']`
