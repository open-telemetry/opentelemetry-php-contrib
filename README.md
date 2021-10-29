# OpenTelemetry php contrib library

## Current Project Status
This project currently lives in a pre-alpha status.  Our current release is not production ready; it has been created in order to receive feedback from the community.

For more information, please, consult the documentation of the main [OpenTelemetry php project](https://github.com/open-telemetry/opentelemetry-php).

## Installation
The recommended way to install the library is through [Composer](http://getcomposer.org):

1.)  Install the composer package using [Composer's installation instructions](https://getcomposer.org/doc/00-intromd#installation-linux-unix-macos).

2.)  Add
```bash
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/open-telemetry/opentelemetry-php-contrib"
        }
    ],
```

To your project's `composer.json` file, as this utility has not reached a stable release status yet, 
and is not yet registered on packagist.org

3.)  Install the dependency with composer:

```bash
$ composer require open-telemetry/opentelemetry-php-contrib
```

## Usage/Examples

### AWS
- You can find examples on how to use the ASW classes in the  [examples directory](/examples/aws/README.md).

### Symfony
#### SdkBundle
- The documentation for the Symfony SdkBundle can be found [here](/src/instrumentation/symfony/OtelSdkBundle/README.md).
- An example symfony application using the SdkBundle can be found [here](https://github.com/tidal/otel-sdk-bundle-example-sf5).


## Development

Please, consult the documentation of the main [OpenTelemetry php project](https://github.com/open-telemetry/opentelemetry-php).

