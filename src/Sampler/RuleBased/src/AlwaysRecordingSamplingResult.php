<?php declare(strict_types=1);
namespace Nevay\OTelSDK\Contrib\Sampler;

use Nevay\OTelSDK\Trace\SamplingResult;
use OpenTelemetry\API\Trace\TraceStateInterface;

/**
 * @internal
 */
final class AlwaysRecordingSamplingResult implements SamplingResult {

    public function __construct(
        private readonly SamplingResult $samplingResult,
    ) {}

    public function shouldRecord(): bool {
        return true;
    }

    public function traceFlags(): int {
        return $this->samplingResult->traceFlags();
    }

    public function traceState(): ?TraceStateInterface {
        return $this->samplingResult->traceState();
    }

    public function additionalAttributes(): iterable {
        return $this->samplingResult->additionalAttributes();
    }
}
