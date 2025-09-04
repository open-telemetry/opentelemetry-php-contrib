# PHP Session Auto-instrumentation for OpenTelemetry

This package provides auto-instrumentation for PHP's native session functions.

## Installation

```bash
composer require open-telemetry/opentelemetry-auto-session
```

## Usage

The instrumentation hooks into PHP's native session functions to provide tracing capabilities. It automatically creates spans for session operations like `session_start()` and `session_destroy()`.

```php
<?php
// Make sure to initialize the OpenTelemetry SDK before using sessions
// ...

// Register the PHP Session instrumentation
\OpenTelemetry\Contrib\Instrumentation\PhpSession\PhpSessionInstrumentation::register();

// Now any session operations will be automatically traced
session_start();
// ... your code ...
session_destroy();
```

## Spans and Attributes

### session.start

When `session_start()` is called, a span named `session.start` is created with the following attributes:

- `code.function_name`: The function name (`session_start`)
- `php.session.options.*`: Any options passed to `session_start()`
- `php.session.id`: The session ID (if session was successfully started)
- `php.session.name`: The session name (if session was successfully started)
- `php.session.status`: Either "active" or "inactive"
- `php.session.cookie.*`: Session cookie parameters

### session.destroy

When `session_destroy()` is called, a span named `session.destroy` is created with the following attributes:

- `code.function_name`: The function name (`session_destroy`)
- `php.session.id`: The session ID (if available)
- `php.session.name`: The session name (if available)

### session.write_close

When `session_write_close()` is called, a span named `session.write_close` is created with the following attributes:

- `code.function.name`: The function name (`session_write_close`)
- `php.session.id`: The session ID (if available)
- `php.session.name`: The session name (if available)

### session.unset

When `session_unset()` is called, a span named `session.unset` is created with the following attributes:

- `code.function.name`: The function name (`session_unset`)
- `php.session.id`: The session ID (if available)
- `php.session.name`: The session name (if available)

### session.abort

When `session_abort()` is called, a span named `session.abort` is created with the following attributes:

- `code.function.name`: The function name (`session_abort`)
- `php.session.id`: The session ID (if available)
- `php.session.name`: The session name (if available)

## License

Apache 2.0
