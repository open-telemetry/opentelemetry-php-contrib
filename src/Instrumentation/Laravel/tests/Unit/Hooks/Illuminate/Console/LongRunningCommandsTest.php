<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Instrumentation\Laravel\Unit\Hooks\Illuminate\Console;

use Illuminate\Console\Command;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Contracts\Console\LongRunningCommand;
use OpenTelemetry\Contrib\Instrumentation\Laravel\Hooks\Illuminate\Console\LongRunningCommands;
use PHPUnit\Framework\TestCase;

class LongRunningCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        putenv(LongRunningCommands::OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS);
    }

    protected function tearDown(): void
    {
        putenv(LongRunningCommands::OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS);
    }

    /**
     * @dataProvider builtInNames
     */
    public function test_built_in_long_running_commands_match(string $name): void
    {
        $this->assertTrue(LongRunningCommands::matchesName($name));
    }

    public static function builtInNames(): iterable
    {
        yield ['queue:work'];
        yield ['queue:listen'];
        yield ['horizon'];
        yield ['horizon:supervisor'];
        yield ['schedule:work'];
        yield ['reverb:start'];
        yield ['octane:start'];
        yield ['pail'];
    }

    /**
     * @dataProvider ordinaryNames
     */
    public function test_ordinary_commands_do_not_match(?string $name): void
    {
        $this->assertFalse(LongRunningCommands::matchesName($name));
    }

    public static function ordinaryNames(): iterable
    {
        yield [null];
        yield [''];
        yield ['migrate'];
        yield ['optimize:clear'];
        yield ['queue:table'];
        yield ['app:import-users'];
    }

    public function test_env_var_extends_the_built_in_list_and_supports_wildcards(): void
    {
        putenv(LongRunningCommands::OTEL_PHP_INSTRUMENTATION_LARAVEL_LONG_RUNNING_COMMANDS . '=app:consume-* , app:relay');

        $this->assertTrue(LongRunningCommands::matchesName('app:consume-orders'));
        $this->assertTrue(LongRunningCommands::matchesName('app:relay'));
        $this->assertTrue(LongRunningCommands::matchesName('queue:work'));
        $this->assertFalse(LongRunningCommands::matchesName('app:consume'));
    }

    public function test_attribute_marks_a_command_regardless_of_name(): void
    {
        $command = new class() extends Command {
            protected $signature = 'app:not-listed-anywhere';
        };
        $annotated = new #[LongRunningCommand] class() extends Command {
            protected $signature = 'app:also-not-listed';
        };

        $this->assertFalse(LongRunningCommands::matches($command));
        $this->assertTrue(LongRunningCommands::matches($annotated));
    }

    public function test_null_command_does_not_match(): void
    {
        $this->assertFalse(LongRunningCommands::matches(null));
    }
}
