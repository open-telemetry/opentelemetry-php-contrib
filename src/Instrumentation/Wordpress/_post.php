<?php

declare(strict_types=1);

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;

$scope = Context::storage()->scope();
if (!$scope) {
    return;
}
$scope->detach();
$span = Span::fromContext($scope->context());
$span->setAttribute('wp.is_admin', is_admin());
if (is_404()) {
    $span->setAttribute(TraceAttributes::HTTP_STATUS_CODE, 404);
    $span->setStatus(StatusCode::STATUS_ERROR);
}
//todo check for other errors?
$span->end();
