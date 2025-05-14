# OpenTelemetry ReactPHP HTTP Browser Examples

These examples are all valid and working; they just show different ways of calling the ReactPHP HTTP Browser, following some of the examples in the [ReactPHP HTTP documentation](https://reactphp.org/http/).

- [http_only.php](http_only.php) - a “pure” example using only the ReactPHP HTTP library.
- [http_only_promises.php](http_only_promises.php) - same as above but with the [Promises](https://reactphp.org/http/#promises) handled in a separate loop.
- [http+async_blocking.php](http+async_blocking.php) - uses the [ReactPHP Async library](https://reactphp.org/async/) to achieve a more [traditional blocking](https://reactphp.org/http/#blocking) request.
- [http+async_nonblocking.php](http+async_nonblocking.php) - uses the [ReactPHP Async library](https://reactphp.org/async/) as intended, to make asynchronous HTTP requests while abstracting away the callables of Promises by taking advantage of PHP fibers.
