<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Curl;

use CurlHandle;
use CurlMultiHandle;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use WeakMap;
use WeakReference;

class CurlInstrumentation
{
    public const NAME = 'curl';

    public static function register(): void
    {
        /** @var WeakMap<CurlHandle, CurlHandleMetadata> */
        $curlHandleToAttributes = new WeakMap();

        /** @var WeakMap<CurlMultiHandle, array> $curlMultiToHandle
         *
         * curlMultiToHandle -> array('started'=>bool,
         *                         'handles'=>
         *                              WeakMap[CurlHandle] => {
         *                                  'finished' => bool,
         *                                  'span' => WeakReference<SpanInterface>
         *                              }
         *                      )
         */
        $curlMultiToHandle = new WeakMap();

        $curlSetOptInstrumentationSuppressed = false;

        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.curl',
            null,
            Version::VERSION_1_30_0->url(),
        );

        hook(
            null,
            'curl_init',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlHandleToAttributes) {
                if ($retVal instanceof CurlHandle) {
                    $curlHandleToAttributes[$retVal] = new CurlHandleMetadata();
                    if (($fullUrl = $params[0] ?? null) !== null) {
                        /** @psalm-suppress PossiblyNullReference */
                        $curlHandleToAttributes[$retVal]->setAttribute(TraceAttributes::URL_FULL, CurlHandleMetadata::redactUrlString($fullUrl));
                    }
                }
            }
        );

        hook(
            null,
            'curl_setopt',
            pre: null,
            post: static function ($_obj, array $params, mixed $retVal) use ($curlHandleToAttributes, &$curlSetOptInstrumentationSuppressed) {
                if ($retVal != true || $curlSetOptInstrumentationSuppressed) {
                    return;
                }

                /** @psalm-suppress PossiblyNullReference */
                $curlHandleToAttributes[$params[0]]->updateFromCurlOption($params[1], $params[2]);
            }
        );

        hook(
            null,
            'curl_setopt_array',
            pre: null,
            post: static function ($_obj, array $params, mixed $retVal) use ($curlHandleToAttributes) {
                if ($retVal != true) {
                    foreach ($params[1] as $option => $value) {
                        if (!curl_setopt($params[0], $option, $value)) {
                            break;
                        }
                    }

                    return;
                }

                foreach ($params[1] as $option => $value) {
                    /** @psalm-suppress PossiblyNullReference */
                    $curlHandleToAttributes[$params[0]]->updateFromCurlOption($option, $value);
                }
            }
        );

        hook(
            null,
            'curl_close',
            pre: static function ($obj, array $params) use ($curlHandleToAttributes) {
                if (count($params) > 0 && $params[0] instanceof CurlHandle) {
                    $curlHandleToAttributes->offsetUnset($params[0]);
                }
            },
            post: null
        );

        hook(
            null,
            'curl_copy_handle',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlHandleToAttributes) {
                if ($params[0] instanceof CurlHandle && $retVal instanceof CurlHandle) {
                    /** @psalm-suppress PossiblyNullReference
                     *  @psalm-suppress PossiblyNullArgument
                     */
                    $curlHandleToAttributes[$retVal] = $curlHandleToAttributes[$params[0]];
                }
            }
        );

        hook(
            null,
            'curl_reset',
            pre: static function ($obj, array $params) use ($curlHandleToAttributes) {
                if (count($params) > 0 && $params[0] instanceof CurlHandle) {
                    $curlHandleToAttributes[$params[0]] = new CurlHandleMetadata();
                }
            },
            post: null
        );

        hook(
            null,
            'curl_exec',
            pre: static function ($obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation, $curlHandleToAttributes, &$curlSetOptInstrumentationSuppressed) {
                if (!($params[0] instanceof CurlHandle)) {
                    return;
                }

                /** @psalm-suppress PossiblyNullReference */
                $spanName = $curlHandleToAttributes[$params[0]]->getAttributes()[TraceAttributes::HTTP_REQUEST_METHOD] ?? 'curl_exec';

                $propagator = Globals::propagator();
                $parent = Context::getCurrent();

                /** @psalm-suppress PossiblyNullReference */
                $builder = $instrumentation->tracer()
                    ->spanBuilder($spanName)
                    ->setParent($parent)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, $function)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setAttributes($curlHandleToAttributes[$params[0]]->getAttributes());

                $span = $builder->startSpan();
                $context = $span->storeInContext($parent);
                $propagator->inject($curlHandleToAttributes[$params[0]], HeadersPropagator::instance(), $context);

                Context::storage()->attach($context);

                $curlSetOptInstrumentationSuppressed = true;

                /** @psalm-suppress PossiblyNullReference */
                $headers = $curlHandleToAttributes[$params[0]]->getRequestHeadersToSend();
                if ($headers) {
                    curl_setopt($params[0], CURLOPT_HTTPHEADER, $headers);
                }

                if (self::isResponseHeadersCapturingEnabled()) {
                    /** @psalm-suppress PossiblyNullReference */
                    curl_setopt($params[0], CURLOPT_HEADERFUNCTION, $curlHandleToAttributes[$params[0]]->getResponseHeaderCaptureFunction());
                }

                if (self::isRequestHeadersCapturingEnabled()) {
                    /** @psalm-suppress PossiblyNullReference */
                    if (!$curlHandleToAttributes[$params[0]]->isVerboseEnabled()) { // we let go of captuing request headers because CURLINFO_HEADER_OUT is disabling CURLOPT_VERBOSE
                        curl_setopt($params[0], CURLINFO_HEADER_OUT, true);
                    }
                    //TODO log?

                }
                $curlSetOptInstrumentationSuppressed = false;

            },
            post: static function ($obj, array $params, mixed $retVal) use ($curlHandleToAttributes) {
                if (!($params[0] instanceof CurlHandle)) {
                    return;
                }

                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($retVal !== false) {
                    self::setAttributesFromCurlGetInfo($params[0], $span);
                } else {
                    $errno = curl_errno($params[0]);
                    if ($errno != 0) {
                        $errorDescription = curl_strerror($errno) . ' (' . $errno . ')';
                        $span->setStatus(StatusCode::STATUS_ERROR, $errorDescription);
                    }
                    $span->setAttribute(TraceAttributes::ERROR_TYPE, 'cURL error (' . $errno . ')');
                }

                /** @psalm-suppress PossiblyNullReference */
                $capturedHeaders = $curlHandleToAttributes[$params[0]]->getCapturedResponseHeaders();
                foreach (self::getResponseHeadersToCapture() as $headerToCapture) {
                    if (($value = $capturedHeaders[strtolower($headerToCapture)] ?? null) != null) {
                        $span->setAttribute(sprintf('http.response.header.%s', strtolower(string: $headerToCapture)), $value);
                    }
                }

                $span->end();
            }
        );

        hook(
            null,
            'curl_multi_init',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlMultiToHandle) {
                if ($retVal instanceof CurlMultiHandle) {
                    $curlMultiToHandle[$retVal] = ['started' => false, 'handles' => new WeakMap()];
                }
            }
        );

        // curl_multi_add_handle(CurlMultiHandle $multi_handle, CurlHandle $handle): int, Returns 0 on success, or one of the CURLM_XXX error codes.
        hook(
            null,
            'curl_multi_add_handle',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlMultiToHandle) {
                if ($retVal == 0) {
                    /** @psalm-suppress PossiblyNullArrayAssignment */
                    $curlMultiToHandle[$params[0]]['handles'][$params[1]] = ['finished' => false, 'span' => null];
                }
            }
        );

        // curl_multi_remove_handle(CurlMultiHandle $multi_handle, CurlHandle $handle): int, Returns 0 on success, or one of the CURLM_XXX error codes.
        hook(
            null,
            'curl_multi_remove_handle',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlMultiToHandle) {
                if ($retVal == 0) {
                    /** @psalm-suppress PossiblyNullArrayAccess
                     *  @psalm-suppress PossiblyNullReference
                     */
                    $curlMultiToHandle[$params[0]]['handles']->offsetUnset($params[1]);
                }
            }
        );

        //  curl_multi_close(CurlMultiHandle $multi_handle): void
        hook(
            null,
            'curl_multi_close',
            pre: static function ($obj, array $params) use ($curlMultiToHandle) {
                if ($params[0] instanceof CurlMultiHandle) {
                    $curlMultiToHandle->offsetUnset($params[0]);
                }
            },
            post: null
        );

        // curl_multi_exec(CurlMultiHandle $multi_handle, int &$still_running): int
        hook(
            null,
            'curl_multi_exec',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlMultiToHandle, $instrumentation, $curlHandleToAttributes, &$curlSetOptInstrumentationSuppressed) {
                if ($retVal == CURLM_OK) {
                    $mHandle = &$curlMultiToHandle[$params[0]];

                    /** @psalm-suppress PossiblyNullArrayAccess */
                    $handles = &$mHandle['handles'];

                    /** @psalm-suppress PossiblyNullArrayAccess */
                    if (!$mHandle['started']) { // on first call to curl_multi_exec we're marking it's a transfer start for all curl handles attached to multi handle
                        $parent = Context::getCurrent();
                        $propagator = Globals::propagator();

                        /** @psalm-suppress PossiblyNullIterator */
                        foreach ($handles as $cHandle => &$metadata) {
                            /** @psalm-suppress PossiblyNullReference */
                            $spanName = $curlHandleToAttributes[$cHandle]->getAttributes()[TraceAttributes::HTTP_REQUEST_METHOD] ?? 'curl_multi_exec';
                            /** @psalm-suppress PossiblyNullReference */
                            $builder = $instrumentation->tracer()
                                ->spanBuilder($spanName)
                                ->setParent($parent)
                                ->setSpanKind(SpanKind::KIND_CLIENT)
                                ->setAttribute(TraceAttributes::CODE_FUNCTION_NAME, 'curl_multi_exec')
                                ->setAttributes($curlHandleToAttributes[$cHandle]->getAttributes());

                            $span = $builder->startSpan();
                            $context = $span->storeInContext($parent);
                            $propagator->inject($curlHandleToAttributes[$cHandle], HeadersPropagator::instance(), $context);

                            Context::storage()->attach($context);

                            $curlSetOptInstrumentationSuppressed = true;
                            $headers = $curlHandleToAttributes[$cHandle]->getRequestHeadersToSend();
                            if ($headers) {
                                curl_setopt($cHandle, CURLOPT_HTTPHEADER, $headers);
                            }
                            if (self::isResponseHeadersCapturingEnabled()) {
                                curl_setopt($cHandle, CURLOPT_HEADERFUNCTION, $curlHandleToAttributes[$cHandle]->getResponseHeaderCaptureFunction());
                            }
                            if (self::isRequestHeadersCapturingEnabled()) {
                                if (!$curlHandleToAttributes[$cHandle]->isVerboseEnabled()) { // we let go of captuing request headers because CURLINFO_HEADER_OUT is disabling CURLOPT_VERBOSE
                                    curl_setopt($cHandle, CURLINFO_HEADER_OUT, true);
                                }
                            }
                            $curlSetOptInstrumentationSuppressed = false;

                            $metadata['span'] = WeakReference::create($span);
                        }
                        $mHandle['started'] = true;
                    }

                    $isRunning = $params[1];
                    if ($isRunning == 0) {
                        // it is the last call to multi - in case curl_multi_info_read might not not be called anytime, we need to finish all spans left
                        /** @psalm-suppress PossiblyNullIterator */
                        foreach ($handles as $cHandle => &$metadata) {
                            if ($metadata['finished'] == false) {
                                $metadata['finished'] = true;
                                self::finishMultiSpan(CURLE_OK, $cHandle, $curlHandleToAttributes, $metadata['span']?->get()); // there is no way to get information if it was OK or not without calling curl_multi_info_read
                            }
                        }

                        $mHandle['started'] = false; // reset multihandle started state in case it will be reused
                        // https://curl.se/libcurl/c/libcurl-multi.html If you want to reuse an easy handle that was added to the multi handle for transfer, you must first remove it from the multi stack and then re-add it again (possibly after having altered some options at your own choice).
                        unset($mHandle['handles']);
                        $mHandle['handles'] = new WeakMap();

                    }
                }
            }
        );

        // curl_multi_info_read(CurlMultiHandle $multi_handle, int &$queued_messages = null): array|false
        hook(
            null,
            'curl_multi_info_read',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlMultiToHandle, $curlHandleToAttributes) {
                $mHandle = &$curlMultiToHandle[$params[0]];

                if ($retVal != false) {
                    if ($retVal['msg'] == CURLMSG_DONE) {
                        /** @psalm-suppress PossiblyNullArrayAccess
                         *  @psalm-suppress PossiblyNullReference
                         */
                        if (!$mHandle['handles']->offsetExists($retVal['handle'])) {
                            return;
                        }

                        /** @psalm-suppress PossiblyNullArrayAccess */
                        $currentHandle = &$mHandle['handles'][$retVal['handle']];
                        /** @psalm-suppress PossiblyNullArrayAccess */
                        if ($currentHandle['finished']) {
                            return;
                        }

                        /** @psalm-suppress PossiblyNullArrayAccess */
                        $currentHandle['finished'] = true;
                        self::finishMultiSpan($retVal['result'], $retVal['handle'], $curlHandleToAttributes, $currentHandle['span']?->get());
                    }
                }
            }
        );
    }

    private static function finishMultiSpan(int $curlResult, CurlHandle $curlHandle, $curlHandleToAttributes, ?SpanInterface $span)
    {
        if ($span === null) {
            return;
        }

        $scope = Context::storage()->scope();
        $scope?->detach();

        if (!$scope || $scope->context() === Context::getCurrent()) {
            return;
        }

        if ($curlResult == CURLE_OK) {
            self::setAttributesFromCurlGetInfo($curlHandle, $span);
        } else {
            $errorDescription = curl_strerror($curlResult) . ' (' . $curlResult . ')';
            $span->setStatus(StatusCode::STATUS_ERROR, $errorDescription);
            $span->setAttribute(TraceAttributes::ERROR_TYPE, 'cURL error (' . $curlResult . ')');
        }

        $capturedHeaders = $curlHandleToAttributes[$curlHandle]->getCapturedResponseHeaders();
        foreach (self::getResponseHeadersToCapture() as $headerToCapture) {
            if (($value = $capturedHeaders[strtolower($headerToCapture)] ?? null) != null) {
                $span->setAttribute(sprintf('http.response.header.%s', strtolower(string: $headerToCapture)), $value);
            }
        }

        $span->end();
    }

    private static function transformHeaderStringToArray(string $header): array
    {
        $lines = explode("\n", $header);
        array_shift($lines); // skip request line

        $headersResult = [];
        foreach ($lines as $line) {
            $line = trim($line, "\r");
            if (empty($line)) {
                continue;
            }

            if (strpos($line, ': ') !== false) {
                /** @psalm-suppress PossiblyUndefinedArrayOffset */
                [$key, $value] = explode(': ', $line, 2);
                $headersResult[strtolower($key)] = $value;
            }
        }

        return $headersResult;
    }

    private static function setAttributesFromCurlGetInfo(CurlHandle $handle, SpanInterface $span)
    {
        $info = curl_getinfo($handle);
        if (($value = $info['http_code']) != 0) {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $value);
        }
        if (($value = $info['download_content_length']) > -1) {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_HEADER . '.content_length', $value);
        }
        if (($value = $info['upload_content_length']) > -1) {
            $span->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $value);
        }
        if (!empty($value = $info['scheme'])) {
            $span->setAttribute(TraceAttributes::URL_SCHEME, $value);
        }
        if (!empty($value = $info['primary_ip'])) {
            $span->setAttribute(TraceAttributes::SERVER_ADDRESS, $value);
        }
        if (($value = $info['primary_port']) != 0) {
            $span->setAttribute(TraceAttributes::SERVER_PORT, $value);
        }

        /** @phpstan-ignore-next-line */
        if (($requestHeader = $info['request_header'] ?? null) != null) {
            $capturedHeaders = self::transformHeaderStringToArray($requestHeader);
            foreach (self::getRequestHeadersToCapture() as $headerToCapture) {
                if (($value = $capturedHeaders[strtolower($headerToCapture)] ?? null) != null) {
                    $span->setAttribute(sprintf('http.request.header.%s', strtolower(string: $headerToCapture)), $value);
                }
            }
        }
    }

    private static function isRequestHeadersCapturingEnabled(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && count(Configuration::getList('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS', [])) > 0) {
            return true;
        }

        return get_cfg_var('otel.instrumentation.http.request_headers') !== false;
    }

    private static function getRequestHeadersToCapture(): array
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && count($values = Configuration::getList('OTEL_PHP_INSTRUMENTATION_HTTP_REQUEST_HEADERS', [])) > 0) {
            return $values;
        }

        return (array) (get_cfg_var('otel.instrumentation.http.request_headers') ?: []);
    }

    private static function isResponseHeadersCapturingEnabled(): bool
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && count(Configuration::getList('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS', [])) > 0) {
            return true;
        }

        return get_cfg_var('otel.instrumentation.http.response_headers') !== false;
    }

    private static function getResponseHeadersToCapture(): array
    {
        if (class_exists('OpenTelemetry\SDK\Common\Configuration\Configuration') && count($values = Configuration::getList('OTEL_PHP_INSTRUMENTATION_HTTP_RESPONSE_HEADERS', [])) > 0) {
            return $values;
        }

        return (array) (get_cfg_var('otel.instrumentation.http.response_headers') ?: []);
    }
}
