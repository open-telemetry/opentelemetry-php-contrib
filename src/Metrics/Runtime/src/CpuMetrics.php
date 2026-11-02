<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Runtime;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

/**
 * @internal
 */
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

        $contextSwitches = $meter->createObservableCounter(
            'process.context_switches',
            '{context_switch}',
            'Number of times the process has been context switched',
        );
        $pagingFaults = $meter->createObservableCounter(
            'process.paging.faults',
            '{fault}',
            'Number of page faults the process has made',
        );

        $meter->batchObserve(
            static function (
                ObserverInterface $cpuObserver,
                ObserverInterface $contextSwitchesObserver,
                ObserverInterface $pagingFaultsObserver,
            ): void {
                /** @var array<string, int> $usage */
                $usage = getrusage();

                $userSeconds = (float) $usage['ru_utime.tv_sec'] + (float) $usage['ru_utime.tv_usec'] / 1_000_000.0;
                $systemSeconds = (float) $usage['ru_stime.tv_sec'] + (float) $usage['ru_stime.tv_usec'] / 1_000_000.0;

                $cpuObserver->observe($userSeconds, ['cpu.mode' => 'user']);
                $cpuObserver->observe($systemSeconds, ['cpu.mode' => 'system']);

                // ru_nvcsw and ru_nivcsw may be absent on some operating systems (e.g. macOS)
                if (isset($usage['ru_nvcsw'])) {
                    $contextSwitchesObserver->observe($usage['ru_nvcsw'], ['process.context_switch.type' => 'voluntary']);
                }
                if (isset($usage['ru_nivcsw'])) {
                    $contextSwitchesObserver->observe($usage['ru_nivcsw'], ['process.context_switch.type' => 'involuntary']);
                }
                if (isset($usage['ru_minflt'])) {
                    $pagingFaultsObserver->observe($usage['ru_minflt'], ['system.paging.fault.type' => 'minor']);
                }
                if (isset($usage['ru_majflt'])) {
                    $pagingFaultsObserver->observe($usage['ru_majflt'], ['system.paging.fault.type' => 'major']);
                }
            },
            $cpuTime,
            $contextSwitches,
            $pagingFaults,
        );
    }
}
