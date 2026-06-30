<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

class CpuMetrics
{
    public const GROUP = 'cpu';

    public static function isAvailable(): bool
    {
        return function_exists('getrusage');
    }

    public static function register(MeterInterface $meter): void
    {
        // getrusage() times in microseconds (ru_utime.tv_usec, ru_stime.tv_usec)
        // combined with seconds (ru_utime.tv_sec, ru_stime.tv_sec)
        $cpuTime = $meter->createObservableCounter(
            'process.cpu.time',
            's',
            'CPU time consumed by the process',
        );

        $voluntaryCS = $meter->createObservableCounter(
            'process.runtime.php.cpu.voluntary_context_switches',
            '{switch}',
            'Number of times the process voluntarily yielded the CPU',
        );

        $involuntaryCS = $meter->createObservableCounter(
            'process.runtime.php.cpu.involuntary_context_switches',
            '{switch}',
            'Number of times the process was preempted involuntarily',
        );

        $meter->batchObserve(
            static function (
                ObserverInterface $cpuObserver,
                ObserverInterface $vcsObserver,
                ObserverInterface $ivcsObserver,
            ): void {
                /** @var array<string, int> $usage */
                $usage = getrusage();

                $userSeconds = (float) $usage['ru_utime.tv_sec'] + (float) $usage['ru_utime.tv_usec'] / 1_000_000.0;
                $systemSeconds = (float) $usage['ru_stime.tv_sec'] + (float) $usage['ru_stime.tv_usec'] / 1_000_000.0;

                $cpuObserver->observe($userSeconds, ['cpu.mode' => 'user']);
                $cpuObserver->observe($systemSeconds, ['cpu.mode' => 'system']);
                // ru_nvcsw and ru_nivcsw may be absent on some operating systems (e.g. macOS)
                $vcsObserver->observe($usage['ru_nvcsw'] ?? 0);
                $ivcsObserver->observe($usage['ru_nivcsw'] ?? 0);
            },
            $cpuTime,
            $voluntaryCS,
            $involuntaryCS,
        );
    }
}
