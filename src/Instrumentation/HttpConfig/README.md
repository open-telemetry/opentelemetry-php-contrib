[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-http-config/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/HttpConfig)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-config-http)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-config-http/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-config-http/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-config-http/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-config-http/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry HTTP configuration

Provides configuration options for HTTP instrumentation packages.

## Configuration

### File-based configuration

```yaml
instrumentation/development:
  php:
    http:
      client:
        capture_url_scheme:
        capture_url_template:
        capture_user_agent_original:
        capture_user_agent_synthetic_type:
        capture_network_transport:
        capture_request_body_size:
        capture_request_size:
        capture_response_body_size:
        capture_response_size:
      server:
        capture_client_port:
        capture_user_agent_synthetic_type:
        capture_network_transport:
        capture_network_local_address:
        capture_network_local_port:
        capture_request_body_size:
        capture_request_size:
        capture_response_body_size:
        capture_response_size:
      uri_sanitizers:
        - default:
        - redact_query_string_values:
            query_keys: [ passwd, secret ]
      known_http_methods: [ CONNECT, DELETE, GET, HEAD, OPTIONS, PATCH, POST, PUT, TRACE, CUSTOM ]
```

### Env-based configuration

```dotenv
OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES="passwd,secret"
OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS="CONNECT,DELETE,GET,HEAD,OPTIONS,PATCH,POST,PUT,TRACE,CUSTOM"
```

## Usage

```php
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\Contrib\Instrumentation\HttpConfig\HttpConfig;

final class CustomHttpInstrumentation implements Instrumentation
{
    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void
    {
        $httpConfig = $configuration->get(HttpConfig::class) ?? new HttpConfig();
        
        $httpConfig->...
    }
}
```
