[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-symfony/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Symfony)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-symfony)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-symfony/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-symfony/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-symfony/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-symfony/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Symfony auto-instrumentation

This is an OpenTelemetry auto-instrumentation package for Symfony framework applications.

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Requirements

* [OpenTelemetry extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#installation)
* OpenTelemetry SDK and exporters (required to actually export traces)

## Overview
The following features are supported:
* root span creation (`Symfony\Component\HttpKernel\HttpKernel::handle` hook)
* context propagation
* HttpClient client span creation (one span per request, even when the client is decorated)
* HTTPClient context propagation
* Message Bus span creation
* Message Transport span creation

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-symfony
```

## Installing dependencies and executing tests

From Symfony subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=symfony
```

### Route path cache

OpenTelemetry semantic conventions define `http.route` as the request's path
template (for example `/blog/{slug}`), not the Symfony route name (for example
`app_blog_show`). Symfony itself only exposes the matched route's name on the
request, and resolving the path template requires the route collection:
`Router::getRouteCollection()` re-runs every route loader on each request when
routes are served from the routing cache, adding several ms per request.

To get the path template instead of the Symfony route name without loading the
application's routes on every request, a route name => path template map is
dumped next to the router's own cache files whenever the router (re)builds its
matcher cache (`cache:warmup`, a cold cache, or route changes in debug mode).
The path template of the matched route is resolved from that map at runtime
and stored on the request as the `_route_path` attribute; if the map is
missing, it is rebuilt once and persisted.

The implementation mimics the Worldia approach to route path caching
(see https://github.com/open-telemetry/opentelemetry-php/issues/2083,
https://github.com/worldia/instrumentation-bundle/pull/12). See also
`OpenTelemetry\Contrib\Instrumentation\Symfony\RoutePathCache`.

The feature is disabled by default and can be enabled with:

```shell
OTEL_PHP_SYMFONY_ROUTE_PATH_CACHE=true
```
