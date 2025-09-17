<?php
/**
 * This file must *only* be used to register SPI components, as it
 * will be pruned automatically via the tbachert/spi plugin.
 */

declare(strict_types=1);

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks;
use OpenTelemetry\Contrib\Instrumentation\Laravel\LaravelInstrumentation;

ServiceLoader::register(Instrumentation::class, LaravelInstrumentation::class);

ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Console\Command::class);

ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Contracts\Console\Kernel::class);
ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Contracts\Http\Kernel::class);
ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Contracts\Queue\Queue::class);

ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Database\Eloquent\Model::class);

ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Foundation\Application::class);
ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Foundation\Console\ServeCommand::class);

ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Queue\Queue::class);
ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Queue\SyncQueue::class);
ServiceLoader::register(Hooks\Hook::class, Hooks\Illuminate\Queue\Worker::class);
