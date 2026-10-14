# Intro
In my company we needed a twig instrumentation to see what was consuming time.
It's a CraftCMS website running on PHP 8.4.

This may not be perfect but it did show us time consumed by twig templates.

# OpenTelemetry Twig auto-Instrumentation

This package provides automatic instrumentation for Twig templates.

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Installation

1.  **Install the OpenTelemetry PHP Extension:** This package relies on the `opentelemetry` PHP extension for automatic hooking.

2.  **Install the Composer package:**
    ```bash
     composer require opentelemetry/opentelemetry-auto-twig
    ```
3. **Enable Twig-Instrumentation:**
    ```
    putenv('OTEL_PHP_INSTRUMENTATION_TWIG_ENABLED=true');
    ```
## Usage

This package provides fully automatic instrumentation. Once installed, it will automatically trace all Twig template rendering. No further configuration is required.

The instrumentation will create spans with the name `twig.render.<type>`, where `<type>` is the type of template being rendered (e.g., `template`, `block`). The spans will include the following attributes:

-   `twig.template`: The name of the template file.
-   `twig.name`: The name of the specific template or block being rendered.
