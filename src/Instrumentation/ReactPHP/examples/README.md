# OpenTelemetry ReactPHP HTTP Browser Examples

These examples show two different ways of using the [ReactPHP HTTP](https://reactphp.org/http/) Browser.

- [http_only.php](http_only.php) - a “pure” example using only the ReactPHP HTTP library.
- [http_with_async.php](http_with_async.php) - uses the [ReactPHP Async library](https://reactphp.org/async/), to make asynchronous HTTP requests while abstracting away the callables of Promises by taking advantage of PHP fibers.
