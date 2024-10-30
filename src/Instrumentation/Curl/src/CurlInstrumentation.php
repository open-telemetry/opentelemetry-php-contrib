<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Curl;

use CurlHandle;
use CurlMultiHandle;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\TraceAttributes;
use WeakMap;
use WeakReference;

class CurlInstrumentation
{
    public const NAME = 'curl';

    public static function register(): void
    {
        /** @var WeakMap<CurlHandle, array> */
        $curlHandleToAttributes = new WeakMap();

        /** @var WeakMap<CurlMultiHandle, array> >
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

        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.curl',
            null,
            'https://opentelemetry.io/schemas/1.24.0'
        );

        hook(
            null,
            'curl_init',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlHandleToAttributes) {
                if ($retVal instanceof CurlHandle) {
                    $curlHandleToAttributes[$retVal] = [TraceAttributes::HTTP_REQUEST_METHOD => 'GET'];
                    if (($handle = $params[0] ?? null) !== null) {
                        $curlHandleToAttributes[$retVal][TraceAttributes::URL_FULL] = self::redactUrlString($handle);
                    }
                }
            }
        );

        hook(
            null,
            'curl_setopt',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlHandleToAttributes) {
                if ($retVal != true) {
                    return;
                }

                $attribute = self::getAttributeFromCurlOption($params[1], $params[2]);
                if ($attribute) {
                    $curlHandleToAttributes[$params[0]][$attribute[0]] = $attribute[1];
                }
            }
        );

        hook(
            null,
            'curl_setopt_array',
            pre: null,
            post: static function ($obj, array $params, mixed $retVal) use ($curlHandleToAttributes) {
                if ($retVal != true) {
                    return;
                }

                foreach ($params[1] as $option => $value) {
                    $attribute = self::getAttributeFromCurlOption($option, $value);
                    if ($attribute) {
                        $curlHandleToAttributes[$params[0]][$attribute[0]] = $attribute[1];
                    }
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
                    $curlHandleToAttributes[$retVal] = $curlHandleToAttributes[$params[0]];
                }
            }
        );

        hook(
            null,
            'curl_reset',
            pre: static function ($obj, array $params) use ($curlHandleToAttributes) {
                if (count($params) > 0 && $params[0] instanceof CurlHandle) {
                    $curlHandleToAttributes[$params[0]] = [TraceAttributes::HTTP_REQUEST_METHOD => 'GET'];
                }
            },
            post: null
        );

        hook(
            null,
            'curl_exec',
            pre: static function ($obj, array $params, ?string $class, ?string $function, ?string $filename, ?int $lineno) use ($instrumentation, $curlHandleToAttributes) {
                if (!($params[0] instanceof CurlHandle)) {
                    return;
                }

                $spanName = $curlHandleToAttributes[$params[0]][TraceAttributes::HTTP_REQUEST_METHOD] ?? 'curl_exec';

                $builder = $instrumentation->tracer()
                    ->spanBuilder($spanName)
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
                    ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
                    ->setAttribute(TraceAttributes::CODE_LINENO, $lineno)
                    ->setAttributes($curlHandleToAttributes[$params[0]]);

                $parent = Context::getCurrent();
                $span = $builder->startSpan();
                Context::storage()->attach($span->storeInContext($parent));
            },
            post: static function ($obj, array $params, mixed $retVal) {
                $scope = Context::storage()->scope();
                if (!$scope) {
                    return;
                }

                $scope->detach();
                $span = Span::fromContext($scope->context());

                if ($retVal !== false) {
                    if ($params[0] instanceof CurlHandle) {
                        self::setAttributesFromCurlGetInfo($params[0], $span);
                    }
                } else {
                    if ($params[0] instanceof CurlHandle) {
                        $errno = curl_errno($params[0]);
                        if ($errno != 0) {
                            $errorDescription = curl_strerror($errno) . ' (' . $errno . ')';
                            $span->setStatus(StatusCode::STATUS_ERROR, $errorDescription);
                        }
                        $span->setAttribute(TraceAttributes::ERROR_TYPE, 'cURL error (' . $errno . ')');
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
            post: static function ($obj, array $params, mixed $retVal) use ($curlMultiToHandle, $instrumentation, $curlHandleToAttributes) {
                if ($retVal == CURLM_OK) {
                    $mHandle = &$curlMultiToHandle[$params[0]];

                    $handles = &$mHandle['handles'];

                    if (!$mHandle['started']) { // on first call to curl_multi_exec we're marking it's a transfer start for all curl handles attached to multi handle
                        $parent = Context::getCurrent();
                        foreach ($handles as $cHandle => &$metadata) {
                            $spanName = $curlHandleToAttributes[$cHandle][TraceAttributes::HTTP_REQUEST_METHOD] ?? 'curl_multi_exec';
                            $builder = $instrumentation->tracer()
                                ->spanBuilder($spanName)
                                ->setSpanKind(SpanKind::KIND_CLIENT)
                                ->setAttribute(TraceAttributes::CODE_FUNCTION, 'curl_multi_exec')
                                ->setAttributes($curlHandleToAttributes[$cHandle]);

                            $span = $builder->startSpan();
                            Context::storage()->attach($span->storeInContext($parent));
                            $metadata['span'] = WeakReference::create($span);
                        }
                        $mHandle['started'] = true;
                    }

                    $isRunning = $params[1];
                    if ($isRunning == 0) {

                        // it is the last call to multi - in case curl_multi_info_read might not not be called anytime, we need to finish all spans left
                        foreach ($handles as $cHandle => &$metadata) {
                            if ($metadata['finished'] == false) {
                                $metadata['finished'] = true;
                                self::finishMultiSpan(CURLE_OK, $cHandle, $metadata['span']->get()); // there is no way to get information if it was OK or not without calling curl_multi_info_read
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
            post: static function ($obj, array $params, mixed $retVal) use ($curlMultiToHandle) {
                $mHandle = &$curlMultiToHandle[$params[0]];

                if ($retVal != false) {
                    if ($retVal['msg'] == CURLMSG_DONE) {
                        if (!$mHandle['handles']->offsetExists($retVal['handle'])) {
                            return;
                        }

                        $currentHandle = &$mHandle['handles'][$retVal['handle']];
                        if ($currentHandle['finished']) {
                            return;
                        }

                        $currentHandle['finished'] = true;
                        self::finishMultiSpan($retVal['result'], $retVal['handle'], $currentHandle['span']->get());
                    }
                }
            }
        );
    }

    private static function finishMultiSpan(int $curlResult, CurlHandle $curlHandle, SpanInterface $span)
    {
        if ($curlResult == CURLE_OK) {
            self::setAttributesFromCurlGetInfo($curlHandle, $span);
        } else {
            $errorDescription = curl_strerror($curlResult) . ' (' . $curlResult . ')';
            $span->setStatus(StatusCode::STATUS_ERROR, $errorDescription);
            $span->setAttribute(TraceAttributes::ERROR_TYPE, 'cURL error (' . $curlResult . ')');
        }
        $span->end();
    }

    private static function redactUrlString(string $fullUrl)
    {
        $urlParts = parse_url($fullUrl);
        if ($urlParts == false) {
            return;
        }

        $scheme   = isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '';
        $host     = isset($urlParts['host']) ? $urlParts['host'] : '';
        $port     = isset($urlParts['port']) ? ':' . $urlParts['port'] : '';
        $user     = isset($urlParts['user']) ? 'REDACTED' : '';
        $pass     = isset($urlParts['pass']) ? ':' . 'REDACTED'  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($urlParts['path']) ? $urlParts['path'] : '';
        $query    = isset($urlParts['query']) ? '?' . $urlParts['query'] : '';
        $fragment = isset($urlParts['fragment']) ? '#' . $urlParts['fragment'] : '';

        return $scheme . $user . $pass . $host . $port . $path . $query . $fragment;
    }

    private static function getAttributeFromCurlOption(int $option, mixed $value): ?array
    {
        switch ($option) {
            case CURLOPT_CUSTOMREQUEST:
                return [TraceAttributes::HTTP_REQUEST_METHOD, $value];
            case CURLOPT_HTTPGET:
                // Based on https://github.com/curl/curl/blob/curl-7_73_0/lib/setopt.c#L841
                return [TraceAttributes::HTTP_REQUEST_METHOD, 'GET'];
            case CURLOPT_POST:
                return [TraceAttributes::HTTP_REQUEST_METHOD, ($value == 1 ? 'POST' : 'GET')];
            case CURLOPT_POSTFIELDS:
                // Based on https://github.com/curl/curl/blob/curl-7_73_0/lib/setopt.c#L269
                return [TraceAttributes::HTTP_REQUEST_METHOD, 'POST'];
            case CURLOPT_PUT:
                return [TraceAttributes::HTTP_REQUEST_METHOD, ($value == 1 ? 'PUT' : 'GET')];
            case CURLOPT_NOBODY:
                // Based on https://github.com/curl/curl/blob/curl-7_73_0/lib/setopt.c#L269
                return [TraceAttributes::HTTP_REQUEST_METHOD, ($value == 1 ? 'HEAD' : 'GET')];
            case CURLOPT_URL:
                return [TraceAttributes::URL_FULL, self::redactUrlString($value)];
            case CURLOPT_USERAGENT:
                return [TraceAttributes::USER_AGENT_ORIGINAL, $value];
        }

        return null;
    }

    private static function setAttributesFromCurlGetInfo(CurlHandle $handle, SpanInterface $span)
    {
        $info = curl_getinfo($handle);
        if (($value = $info['http_code']) != 0) {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $value);
        }
        if (($value = $info['download_content_length']) > -1) {
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_CONTENT_LENGTH, $value);
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
    }
}
