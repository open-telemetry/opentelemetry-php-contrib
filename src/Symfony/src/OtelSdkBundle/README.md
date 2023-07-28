# OpenTelemetry Symfony SdkBundle

- Adds configuration for the [OpenTelemetry php SDK](https://github.com/open-telemetry/opentelemetry-php-contrib) to a Symfony project (^4.4|^5.3|^6.0).
- Populates service objects in the Symfony DI container based on given configuration.
- Autoinstrumentation of Symfony projects will be addressed in an upcoming `InstrumentationBundle`, which
will sit on top of the `SdkBundle`.

> Notice: For now this bundle covers the `trace` and `resource` parts of the OpenTelemetry
> [specification](https://github.com/open-telemetry/opentelemetry-specification) and 
> [PHP library](https://github.com/open-telemetry/opentelemetry-php) with `metrics` soon™ to come and `logging`, once the
> appropriate specification is marked as stable and the PHP library implements it.

**TLDR: If you just want to give this bundle a try, and see how it works, you will find a link to an example
symfony application using the bundle at the very bottom of this doc.**

## 1. Prerequisites

- An existing Symfony project (^4.4|^5.3|^6.0), or create a [new project](https://symfony.com/doc/current/setup.html).
- An installation of a trace collector supported by the [OpenTelemetry php library](https://github.com/open-telemetry/opentelemetry-php-contrib)
- Some knowledge of the [OpenTelemetry specification](https://github.com/open-telemetry/opentelemetry-specification) and
  [PHP library](https://github.com/open-telemetry/opentelemetry-php) would be helpful, however both, the PHP library
  and this bundle, aim to abstract the complexity of the specification details away. We assume, you have a basic understanding on
  how `distributed tracing` works in general. You can find an overview of the terms used in the specification
  in its [glossary](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/glossary.md).

If you don't have any collector installation at hand, you can use [docker-compose](https://docs.docker.com/compose/)
and create a `docker-compose.yaml` file in the root of your project with the content as follows:

```yaml
version: '3.7'
services:
    jaeger:
        image: jaegertracing/all-in-one
        environment:
            COLLECTOR_ZIPKIN_HTTP_PORT: 9412
        ports:
            - "9412:9412"
            - "16686:16686"
```

Run  `docker-compose up -d` and you will have an local installation of [Jaeger](https://www.jaegertracing.io/) to collect your data.
Your local instance will listen listen on the endpoint http://localhost:9412/api/v2/spans for data and you can access the GUI
at http://localhost:16686/. (Keep in mind, if you define you php service in docker-compose as well, you will have to change
the host from `localhost` to `jaeger` in the configurations described below)

## 2. Installation

### 2.1. Install PHP library/SDK dependencies

Take a look at the documentation of the  [PHP library](https://github.com/open-telemetry/opentelemetry-php) on how to install its dependencies.

### 2.2. Install the Bundle

1. Add
```bash
    "minimum-stability": "dev",
    "prefer-stable": true,
```

To your project's `composer.json` file, as this utility has not reached a stable release status yet.

2. Install the package with composer:

```bash
$ composer require open-telemetry/contrib-sdk-bundle
```


### 2.3. Enable the Bundle

If you have symfony/flex installed in your project, the bundle should be automatically be registered in your project's
`bundles.php` file. If for some reason the bundle could not be automatically detected, add the following line in
`bundles.php` file of your project

````php
// config/bundles.php

return [
    // ...
    OpenTelemetry\Contrib\Symfony\OtelSdkBundle\OtelSdkBundle::class => ['all' => true],
    // ...
];
````

### 2.4. Configure the Installed Bundle

#### 2.3.1. Minimal Configuration

*Notice: Following examples use YAML as the config format. You can of course use XML and PHP as well to configure
this bundle. If you are not familiar with how XML or PHP configuration in Symfony works, take a look at the
[documentation](https://symfony.com/doc/current/configuration.html#configuration-formats).*

Now that the bundles is downloaded and registered, you have to add some configuration.
Create a file called `otel_sdk.yaml` in your project's `config/packges` directory (Once the `symfony/flex recipe` for
this bundle is registered in the official [recipe contrib repo](https://github.com/symfony/recipes-contrib), this file will be automatically created for you).
A minimal configuration for the bundle looks like this:

````yaml
otel_sdk:
    resource:
        attributes:
            service.name: "OtelBundle Demo app"
````

The resource's `service.name` attribute is the only mandatory configuration, however in order for the bundle to be useful,
you need to configure at least one Trace Exporter, which can talk to an appropriate Trace Collector.

#### 2.3.1. Configuring a Trace Exporter

Assuming you installed Jaeger as described above, your configuration would look this:

````yaml
otel_sdk:
    resource:
        attributes:
            service.name: "OtelBundle Demo app"
    trace:
      exporters:
        - type: zipkin
          url: http://localhost:9412/api/v2/spans
````

If you have multiple Exporters/Collectors, you can just add them like this:

````yaml
otel_sdk:
    resource:
        attributes:
            service.name: "OtelBundle Demo app"
    trace:
      exporters:
        - type: zipkin
          url: http://localhost:9412/api/v2/spans
        - type: otlp
          url: http://localhost:9411/api/v2/spans
````

Or equivalent to the `type` and endpoint `url` example above.

**2.3.2. Further Configuration**

The bundle comes with advanced configuration for (almost) all user facing parts of the 
[OpenTelemetry php SDK](https://github.com/open-telemetry/opentelemetry-php-contrib), which will be documented here, soon.
For now, please refer to the configurations the bundle is tested against:

- [minimal](../../tests/Integration/OtelSdkBundle/DependencyInjection/config/minimal/config.yaml)
- [simple](../../tests/Integration/OtelSdkBundle/DependencyInjection/config/simple/config.yaml)
- [resource](../../tests/Integration/OtelSdkBundle/DependencyInjection/config/resource/config.yaml)
- [samplers](../../tests/Integration/OtelSdkBundle/DependencyInjection/config/sampler/config.yaml)
- [span](../../tests/Integration/OtelSdkBundle/DependencyInjection/config/span/config.yaml)
- [exporters](../../tests/Integration/OtelSdkBundle/DependencyInjection/config/exporters/config.yaml)
- [full](../../tests/Integration/OtelSdkBundle/DependencyInjection/config/full/config.yaml)
- [disabled](../../tests/Integration/OtelSdkBundle/DependencyInjection/config/disabled/config.yaml)

## 3. Usage

> Notice: The examples assume you are running Symfony in a single-threaded [runtime](https://github.com/php-runtime/runtime) like PHP-FPM and/or a "traditional" 
> web server. If you are using a more modern multi-threaded or event-loop based [runtime](https://github.com/php-runtime/runtime)
> like [Roadrunner](https://roadrunner.dev/), [Swoole](https://www.swoole.co.uk/), [Swow](https://github.com/swow/swow),
> [ReactPHP](https://reactphp.org/), [Amp](https://amphp.org/), [Revolt](https://revolt.run/), [Workerman](https://github.com/walkor/Workerman),
> etc., the examples won't necessarily work. We will address how to use the bundle with said runtimes, once the bundle is
> better tested against them.


The bundle populates all needed (and configured) services to allow distributed tracing with the SDK in Symfony's 
DI container, however the intended usage according to the specification is to only interact with an instance of a [Tracer](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/Trace/Tracer.php)
or [TracerProvider](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/Trace/TracerProvider.php) as your entry point. 
In a programmatic setup you get an instance of a Tracer by calling the method [getTracer](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/Trace/TracerProvider.php#L58)
on the TracerProvider. The bundle uses the TracerProvider as a factory for the Tracer instance, so if you don't need any
of the other features of the TracingProvider, you can simply work with a Tracer instance. For the matter of simplicity, we
will use the Tracer instance in this example. You can find an advanced example on how to use the TracerProvider in the demo 
application (link below). Also keep in mind, the examples are not meant to show 'best practices' on how to use or work 
with the SDK and/or tracing, they are just a way to get you started.

### 3.1. Setup up a Kernel Listener or Subscriber
- As an entrypoint for our tracing we can create a [Listener or Subscriber](https://symfony.com/doc/current/event_dispatcher.html) 
which will listen to [events of the HTTPKernel](https://symfony.com/doc/current/reference/events.html). For this example
we will create a Subscriber, since they require less (or actually none) configuration and are more flexible.
- With `autowire` and `autoconfigure` activated in your Symfony configuration (should be on per default), all you need is to type
hint the [Tracer](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/Trace/Tracer.php) in your constructor
 and Symfony will automatically inject the Tracer instance, when creating the Listener service.
- Once we have the Tracer instance at hand, we can create trace spans from it.

So our Listener class could look like this:

```php
// src/EventSubscriber/TracingKernelSubscriber.php
<?php

namespace App\EventSubscriber;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TracingKernelSubscriber implements EventSubscriberInterface
{
    private TracerProvider $provider;
    private ?SpanInterface $mainSpan = null;

    public function __construct(TracerProvider $provider)
    {
        // store a reference to the TracerProvider in case we want to create
        // more spans on different events (not covered in this example)
        $this->provider = $provider;
    }

    public function onRequestEvent(RequestEvent $event)
    {
        // Create our main span and activate it
        $this->mainSpan = $this->provider
            ->getTracer('io.opentelemetry.contrib.php')
            ->spanBuilder('main')
            ->startSpan();
        $this->mainSpan->activate();
    }

    public function onTerminateEvent(TerminateEvent $event): void
    {
        // end our main span once the request has been processed and the kernel terminates.
        $this->mainSpan->end();
    }

    public static function getSubscribedEvents(): array
    {
        // return the subscribed events, their methods and priorities
        // use a very high integer for the Request priority and a
        // very low negative integer for the Terminate priority, so the listener
        // will be the first and last one to be called respectively.
        return [
            KernelEvents::REQUEST => [['onRequestEvent', 10000]],
            KernelEvents::TERMINATE => [['onTerminateEvent', -10000]],
        ];
    }
}

```

With this Listener created, you should already see a single span in your tracing collector (Jaeger, etc.) once you 
request any page of your Symfony application.

> Notice: In above example the first span is created at the time the Listener is instantiated. There is a 
> latency between the request coming in and the Listener being created, which is the time it takes for the HttpKernel 
> to boot. So the trace does not cover the whole time it took for the request to be processed. There are ways to address
> this issue without tempering with the front controller (index.php). In essence, one can query the Kernel instance for
> its instantiation time and retrospectively adjust the set start time of the first span. The InstrumentationBundle will take care of 
> this automatically.

### 3.1. Create sub spans in the Controller

In the same way we just used a type hint to inject the Tracer into the Subscriber to record certain operations of our 
business logic.
> Notice: While you can inject the Tracer into the Controller, it is not a good idea to do something like that in a "real"
> application. While metrics are important, they are cross-cutting concerns and your business logic should not know about
> them, or even depend on them. Also, business logic should not depend on 3rd party code in the first place. So in 
> reality you should create a service and/or custom Event/Listener to interact with the Tracer and create an adapter for
> the SDK.

With above's notice out of the way, the Controller could look like this:

```php
// src/Controller/HelloController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use OpenTelemetry\SDK\Trace\Tracer;

class HelloController
{
private Tracer $tracer;

    public function __construct(Tracer $tracer)
    {
        $this->tracer = $tracer;
    }

    /**
     * @Route("/hello", name="hello")
     */
    public function index(): Response
    {
        $span = $this->tracer->spanBuilder(__METHOD__)->startSpan();

        // DO stuff

        $span->end();

        return new Response('Hello')
    }
}

```

Now when you request the appropriate page (e.: `http://localhost/hello`), you should be able to see the main span and 
the child span in your tracing collector. 

### 3.3. Further usage 

For further usage of spans (events, attributes, etc.), please consult the documentation of the 
[PHP library](https://github.com/open-telemetry/opentelemetry-php) or take a look at the demo application below.

## 4. Demo Application

You can find a demo application using the SdkBundle [here](https://github.com/opentelemetry-php/otel-sdk-bundle-example-sf5). The demo extends the examples given above and comes with a 
docker-compose setup, so it is easy to try out.
