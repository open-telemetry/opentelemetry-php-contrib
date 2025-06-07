# OpenTelemetry ReactPHP HTTP Browser Examples

These examples show a few different ways of using the [ReactPHP HTTP](https://reactphp.org/http/) Browser.

### [http_requests.php](http_requests.php)

This is a “pure” example using only the ReactPHP HTTP library and it’s dependencies.

Here the spans end once the full HTTP response is received. Note that the root (parent) span ends as soon as the other spans have started.

#### Example output:

```
{root span - 1.033s}
{span for 400:Bad Request - 0.144s}
[HTTP/1.1 400 Bad Request] http://postman-echo.com:443/get
{span for /stream - 0.450s}
[HTTP/1.1 200 OK] https://postman-echo.com/stream/33554432
{span for /get - 0.604s}
[HTTP/1.1 200 OK] https://postman-echo.com/get
{span for /delay - 1.996s}
[HTTP/1.1 200 OK] https://postman-echo.com/delay/1
```

### [http_streaming_requests.php](http_streaming_requests.php)

This is still using only the ReactPHP HTTP library and it’s dependencies, and handles all responses as streaming responses.

Here the spans end once the HTTP headers are received but potentially before the full message body is received. Like the previous example, the root (parent) span ends as soon as the other spans have started.

#### Example output:

```
{root span - 0.022s}
{span for /get - 0.212s}
[HTTP/1.1 200 OK] https://postman-echo.com/get: headers received.
[HTTP/1.1 200 OK] https://postman-echo.com/get: body received.
{span for /stream - 0.351s}
[HTTP/1.1 200 OK] https://postman-echo.com/stream/33554432: headers received.
{span for 400:Bad Request - 0.081s}
[HTTP/1.1 400 Bad Request] http://postman-echo.com:443/get
[HTTP/1.1 200 OK] https://postman-echo.com/stream/33554432: body received.
{span for /delay - 1.173s}
[HTTP/1.1 200 OK] https://postman-echo.com/delay/1: headers received.
[HTTP/1.1 200 OK] https://postman-echo.com/delay/1: body received.
```

### [http_requests_with_async.php](http_requests_with_async.php)

This uses the [ReactPHP Async library](https://reactphp.org/async/) to make asynchronous HTTP requests while abstracting away the callables of Promises by taking advantage of PHP fibers.

The responses are not handled as streaming, so as in the first example, the spans end once the full HTTP response is received. Unlike the previous two examples, the root (parent) span is “held open” until all child spans have ended.

#### Example output:

```
Some other event loop event
{span for /get - 0.189s}
[HTTP/1.1 200 OK] https://postman-echo.com/get
Some other event loop event
{span for /stream - 1.685s}
[HTTP/1.1 200 OK] https://postman-echo.com/stream/33554432
{span for 400:Bad Request - 0.081s}
[HTTP/1.1 400 Bad Request] http://postman-echo.com:443/get
Some other event loop event
Some other event loop event
{span for /delay - 1.182s}
[HTTP/1.1 200 OK] https://postman-echo.com/delay/1
{root span - 4.169s}
Some other event loop event
```
