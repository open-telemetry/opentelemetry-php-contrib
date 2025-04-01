[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-test-utils/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Utils/Test)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-test-utils)
[![Latest Version](http://poser.pugx.org/open-telemetry/test-utils/v/unstable)](https://packagist.org/packages/open-telemetry/test-utils/)
[![Stable](http://poser.pugx.org/open-telemetry/test-utils/v/stable)](https://packagist.org/packages/open-telemetry/test-utils/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Test Utilities

This package provides testing utilities for OpenTelemetry PHP instrumentations. It includes tools to help test and validate trace structures, span relationships, and other OpenTelemetry-specific functionality.

## Features

### TraceStructureAssertionTrait

The `TraceStructureAssertionTrait` provides a method to assess if spans match an expected trace structure. It's particularly useful for testing complex trace hierarchies and relationships between spans.

Key features:
- Support for hierarchical span relationships
- Verification of span names, kinds, attributes, events, and status
- Flexible matching with strict and non-strict modes
- Detailed error messages for failed assertions

## Requirements

* PHP 7.4 or higher
* OpenTelemetry SDK and API (for testing)
* PHPUnit 9.5 or higher

## Usage

### TraceStructureAssertionTrait

Add the trait to your test class:

```php
use OpenTelemetry\TestUtils\TraceStructureAssertionTrait;
use PHPUnit\Framework\TestCase;

class MyTest extends TestCase
{
    use TraceStructureAssertionTrait;

    // Your test methods...
}
```

Use the `assertTraceStructure` method to verify trace structures:

```php
public function testTraceStructure(): void
{
    // Create spans using the OpenTelemetry SDK
    // ...

    // Define the expected structure
    $expectedStructure = [
        [
            'name' => 'root-span',
            'kind' => SpanKind::KIND_SERVER,
            'children' => [
                [
                    'name' => 'child-span',
                    'kind' => SpanKind::KIND_INTERNAL,
                    'attributes' => [
                        'attribute.one' => 'value1',
                        'attribute.two' => 42,
                    ],
                    'events' => [
                        [
                            'name' => 'event.processed',
                            'attributes' => [
                                'processed.id' => 'abc123',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'another-child-span',
                    'kind' => SpanKind::KIND_CLIENT,
                    'status' => [
                        'code' => StatusCode::STATUS_ERROR,
                        'description' => 'Something went wrong',
                    ],
                ],
            ],
        ],
    ];

    // Assert the trace structure
    $this->assertTraceStructure($spans, $expectedStructure);
}
```

The `assertTraceStructure` method takes the following parameters:
- `$spans`: An array or ArrayObject of spans (typically from an InMemoryExporter)
- `$expectedStructure`: An array defining the expected structure of the trace
- `$strict` (optional): Whether to perform strict matching (all attributes must match)

## Installation via composer

```bash
$ composer require --dev open-telemetry/test-utils
```

## Installing dependencies and executing tests

From the Test Utils subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```
