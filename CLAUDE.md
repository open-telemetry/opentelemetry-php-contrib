# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository Overview

This is the OpenTelemetry PHP contrib monorepo — a collection of independent sub-projects providing auto-instrumentation, propagation, exporters, resource detectors, samplers, and other extensions for the [opentelemetry-php](https://github.com/open-telemetry/opentelemetry-php) core library.

Each sub-project under `src/` is a standalone Composer package with its own `composer.json`, tests, and static analysis configs. The root `composer.json` aggregates all sub-projects via PSR-4 autoloading and Composer `replace`.

## Development Commands

All development runs inside Docker via the Makefile. Copy `.env.dist` to `.env` before first use.

**Target a specific sub-project** using the `PROJECT` variable (path relative to `src/`):

```bash
PROJECT=Instrumentation/PDO PHP_VERSION=8.4 make all    # Full pipeline for one project
PROJECT=Aws make test                                    # Tests only
PROJECT=Instrumentation/Guzzle make style                # Code style fix
```

Key make targets:
- `make build` — Build the Docker image
- `make install` / `make update` — Composer install/update
- `make test` — Run all PHPUnit tests
- `make test-unit` / `make test-integration` — Run test suites separately
- `make style` — Run php-cs-fixer (auto-fixes)
- `make psalm` — Run Psalm static analysis
- `make phpstan` — Run PHPStan static analysis
- `make all-checks` — Style + psalm + phpstan + tests
- `make all` — Update deps + all checks

To run a single test file or filter locally within a project:
```bash
# From inside the container (make bash), within the project dir:
vendor/bin/phpunit --filter=testMethodName
vendor/bin/phpunit tests/Unit/SomeTest.php
```

## CI

The GitHub Actions workflow (`.github/workflows/php.yml`) runs every sub-project independently against PHP 8.1–8.4. Each project runs: composer validate, php-cs-fixer (dry-run), psalm, phpstan, and phpunit. Some projects (MongoDB, ExtAmqp, ExtRdKafka, MySqli, PostgreSql) spin up infrastructure services for integration tests.

## Architecture

### Sub-project categories (`src/`)

- **Instrumentation/** — Auto-instrumentation for PHP libraries/frameworks (PDO, Laravel, Symfony, Guzzle, etc.). These use the `ext-opentelemetry` PHP extension's `hook()` function to instrument classes without code changes.
- **Propagation/** — Context propagation formats (CloudTrace, ServerTiming, etc.)
- **ResourceDetectors/** — Auto-detect cloud environment attributes (Azure, Container, DigitalOcean)
- **Sampler/** — Custom sampling strategies (RuleBased, Xray)
- **Exporter/** — Export backends (Instana)
- **Logs/** — Log bridge integrations (Monolog)
- **Aws/** — AWS-specific SDK utilities (Xray ID generator, Lambda propagator/detector)
- **Symfony/** — Symfony bundles (OtelBundle, OtelSdkBundle)
- **Shims/** — Compatibility layers (OpenTracing shim)
- **Context/** — Alternative context storage (Swoole)
- **SqlCommenter/** — SQL comment injection for trace correlation
- **Utils/Test** — Shared test utilities

### Auto-instrumentation pattern

Each instrumentation package follows this structure:
1. `src/XxxInstrumentation.php` — Static `register()` method that hooks into target class methods using `OpenTelemetry\Instrumentation\hook()`, creating spans with appropriate attributes
2. `_register.php` — Bootstrap file loaded via Composer `autoload.files`; checks for SDK and `ext-opentelemetry`, then calls `register()`
3. `.php-cs-fixer.php`, `phpstan.neon.dist`, `psalm.xml.dist`, `phpunit.xml.dist` — Per-project tool configs

### Dependency layering (enforced by deptrac)

`API` → `Context`, `SemConv`; `SDK` → `API` + PSR interfaces; `Contrib` → `SDK`

Contrib packages must depend on SDK (or API) interfaces, never on other contrib packages.

## Code Style

- Enforced by php-cs-fixer: PSR-2, `declare(strict_types=1)` required, short array syntax, single quotes, ordered imports, trailing commas in multiline
- All source files must start with `declare(strict_types=1);`

## Issues

Issues are tracked in the main [opentelemetry-php repo](https://github.com/open-telemetry/opentelemetry-php/issues), prefixed with `[opentelemetry-php-contrib]`.
