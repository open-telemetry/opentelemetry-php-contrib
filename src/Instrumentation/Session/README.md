# PHP Session Auto-instrumentation for OpenTelemetry

This package provides auto-instrumentation for PHP's native session functions.

## Installation

```bash
composer require open-telemetry/opentelemetry-auto-php-session
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
- `code.file_path`: The file path where the function was called
- `code.line_number`: The line number where the function was called
- `session.options.*`: Any options passed to `session_start()`
- `session.id`: The session ID (if session was successfully started)
- `session.name`: The session name (if session was successfully started)
- `session.status`: Either "active" or "inactive"
- `session.cookie.*`: Session cookie parameters

### session.destroy

When `session_destroy()` is called, a span named `session.destroy` is created with the following attributes:

- `code.function_name`: The function name (`session_destroy`)
- `code.file_path`: The file path where the function was called
- `code.line_number`: The line number where the function was called
- `session.id`: The session ID (if available)
- `session.name`: The session name (if available)
- `session.destroy.success`: Boolean indicating if the session was successfully destroyed

### session.write_close

When `session_write_close()` is called, a span named `session.write_close` is created with the following attributes:

- `code.function.name`: The function name (`session_write_close`)
- `code.filepath`: The file path where the function was called
- `code.lineno`: The line number where the function was called
- `session.id`: The session ID (if available)
- `session.name`: The session name (if available)
- `session.write_close.success`: Boolean indicating if the session was successfully written and closed

### session.unset

When `session_unset()` is called, a span named `session.unset` is created with the following attributes:

- `code.function.name`: The function name (`session_unset`)
- `code.filepath`: The file path where the function was called
- `code.lineno`: The line number where the function was called
- `session.id`: The session ID (if available)
- `session.name`: The session name (if available)
- `session.unset.success`: Boolean indicating if the session variables were successfully unset

### session.abort

When `session_abort()` is called, a span named `session.abort` is created with the following attributes:

- `code.function.name`: The function name (`session_abort`)
- `code.filepath`: The file path where the function was called
- `code.lineno`: The line number where the function was called
- `session.id`: The session ID (if available)
- `session.name`: The session name (if available)
- `session.abort.success`: Boolean indicating if the session was successfully aborted

## License

Apache 2.0
