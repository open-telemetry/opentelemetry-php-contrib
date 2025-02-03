# Contributing to OpenTelemetry PHP Contrib

## Introduction

Thank you for your interest in contributing to OpenTelemetry PHP Contrib! This guide will help you set up your development environment, understand the contribution workflow, and troubleshoot common issues.

## Prerequisites

Before you begin, ensure you have the following installed:

- PHP (Supported versions: 8.1, 8.2, 8.3)
- [Composer](https://getcomposer.org/)
- [Docker](https://docs.docker.com/engine/install/)
- [Docker Compose](https://docs.docker.com/compose/install/)
- GNU Make (for running development commands)

## Workflow

1. Fork the repository and create a new branch.
2. Make your changes and commit them with clear messages.
3. Run `make all-checks` before submitting a PR to ensure your changes pass all checks.
4. Submit a pull request with a clear description.

### Setting Up the Development Environment

1. Clone the repository:
   ```sh
   git clone https://github.com/open-telemetry/opentelemetry-php-contrib.git
   cd opentelemetry-php-contrib
   ```

2. Copy the environment file:
   ```sh
   cp .env.dist .env
   ```

3. Install dependencies:
   ```sh
   make install
   ```

## Testing

To run all tests, execute:
```sh
make test
```

If you need to test against a specific PHP version:
```sh
PHP_VERSION=8.1 make test
```

## Common Issues & Troubleshooting

### 1. PHP and Dependency Management Issues

**Error:** Composer dependency resolution failure due to missing `composer.lock` file.

**Solution:** Generate a new `composer.lock` file by running:
```sh
composer update --lock
```
Then, reinstall dependencies:
```sh
composer install
```

### 2. PHPUnit Tests Not Running

**Error:** Running tests results in zero tests executed.

**Solution:** Ensure PHPUnit is correctly configured by checking `phpunit.xml`. If tests are not found, update the test directory paths in `phpunit.xml`. Then, retry running the tests.


## Code Style & Static Analysis

We use PHP-CS-Fixer, Psalm, and PHPStan for code quality checks. Before committing, run:
```sh
make style
make psalm
make phpstan
```

## API Documentation

To generate API documentation:
```sh
make phpdoc
```

## Further Help

- Join our discussions on the CNCF Slack in the [`#opentelemetry-php`](https://cloud-native.slack.com/archives/C01NFPCV44V) channel.
- Check the [public meeting notes](https://docs.google.com/document/d/1i1E4-_y4uJ083lCutKGDhkpi3n4_e774SBLi9hPLocw/edit) for past updates.

Thank you for contributing to OpenTelemetry PHP Contrib! 



Maintainers ([@open-telemetry/php-maintainers](https://github.com/orgs/open-telemetry/teams/php-maintainers)):

- [Bob Strecansky](https://github.com/bobstrecansky), Mailchimp

Find more about the maintainer role in [community repository](https://github.com/open-telemetry/community/blob/master/community-membership.md#maintainer)

Approvers ([@open-telemetry/php-approvers](https://github.com/orgs/open-telemetry/teams/php-approvers)):

- [Levi Morrison](https://github.com/morrisonlevi), Datadog
- [Austin Schoen](https://github.com/AustinSchoen), Mailchimp
- [Beniamin Calota](https://github.com/beniamin), eMag

Find more information about the approver role in the [community repository](https://github.com/open-telemetry/community/blob/master/community-membership.md#approver)

Triagers ([@open-telemetry/php-triagers](https://github.com/orgs/open-telemetry/teams/php-triagers)):

- [Jodee Varney](https://github.com/jodeev), Splunk

Find more information about the triager role in the [community repository](https://github.com/open-telemetry/community/blob/master/community-membership.md#triager)
