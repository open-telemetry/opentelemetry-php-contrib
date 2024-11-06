[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-curl/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/curl)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-curl)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-curl/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-curl/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-curl/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-curl/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry curl auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via composer, and client kind spans will automatically be created when calling `curl_exec` or `curl_multi_exec` functions.
Additionally, distributed tracing is supported by setting the `traceparent` header.

## Limitations
The curl_multi instrumentation is not resilient to shortcomings in the application and requires proper implementation. If the application does not call the curl_multi_info_read function, the instrumentation will be unable to measure the execution time for individual requests-time will be aggregated for all transfers. Similarly, error detection will be impacted, as the error code information will be missing in this case. In case of encountered issues, it is recommended to review the application code and adjust it to match example #1 provided in [curl_multi_exec documentation](https://www.php.net/manual/en/function.curl-multi-exec.php).

To ensure the stability of the monitored application, capturing request headers sent to the server works only if the application does not use the `CURLOPT_VERBOSE` option.

## Configuration

### Disabling curl instrumentation

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=curl
```

### Request and response headers capturing

Curl auto-instrumentation enables capturing headers from both requests and responses. This feature is disabled by default and be enabled through environment variables or array directives in the `php.ini` configuration file.

To enable response header capture from the server, specify the required headers as shown in the example below. In this case, the "Content-Type" and "Server" headers will be captured. These options values are case-insensitive:

#### Environment variables configuration

```bash
OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS=content-type,server
OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=host,accept
```

#### php.ini configuration

```ini
OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS=content-type,server
; or
otel.instrumentation.http.response_headers[]=content-type
otel.instrumentation.http.response_headers[]=server
```


Similarly, to capture headers sent in a request to the server, use the following configuration:

```ini
OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS=host,accept
; or
otel.instrumentation.http.request_headers[]=host
otel.instrumentation.http.request_headers[]=accept
```