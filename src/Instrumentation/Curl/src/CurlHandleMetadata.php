<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Curl;

use CurlHandle;
use OpenTelemetry\SemConv\TraceAttributes;

class CurlHandleMetadata
{
    private array $attributes = [];

    private array $headers = [];

    private array $headersToPropagate = [];

    private mixed $originalHeaderFunction = null;
    private array $responseHeaders = [];

    private bool $verboseEnabled = false;

    public function __construct()
    {
        $this->attributes = [TraceAttributes::HTTP_REQUEST_METHOD => 'GET'];
        $this->headers = [];
        $headersToPropagate = [];
    }

    public function isVerboseEnabled(): bool
    {
        return $this->verboseEnabled;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttribute(string $key, mixed $value)
    {
        $this->attributes[$key] = $value;
    }

    public function setHeaderToPropagate(string $key, $value): CurlHandleMetadata
    {
        $this->headersToPropagate[] = $key . ': ' . $value;

        return $this;
    }

    public function getRequestHeadersToSend(): ?array
    {
        if (count($this->headersToPropagate) == 0) {
            return null;
        }
        $headers = array_merge($this->headersToPropagate, $this->headers);
        $this->headersToPropagate = [];

        return $headers;
    }

    public function getCapturedResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function getResponseHeaderCaptureFunction()
    {
        $this->responseHeaders = [];
        $func = function (CurlHandle $handle, string $headerLine): int {
            $header = trim($headerLine, "\n\r");

            if (strlen($header) > 0) {
                if (strpos($header, ': ') !== false) {
                    /** @psalm-suppress PossiblyUndefinedArrayOffset */
                    [$key, $value] = explode(': ', $header, 2);
                    $this->responseHeaders[strtolower($key)] = $value;
                }
            }

            if ($this->originalHeaderFunction) {
                return call_user_func($this->originalHeaderFunction, $handle, $headerLine);
            }

            return strlen($headerLine);
        };

        return \Closure::bind($func, $this, self::class);
    }

    public function updateFromCurlOption(int $option, mixed $value)
    {
        switch ($option) {
            case CURLOPT_CUSTOMREQUEST:
                $this->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $value);

                break;
            case CURLOPT_HTTPGET:
                // Based on https://github.com/curl/curl/blob/curl-7_73_0/lib/setopt.c#L841
                $this->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, 'GET');

                break;
            case CURLOPT_POST:
                $this->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, ($value == 1 ? 'POST' : 'GET'));

                break;
            case CURLOPT_POSTFIELDS:
                // Based on https://github.com/curl/curl/blob/curl-7_73_0/lib/setopt.c#L269
                $this->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, 'POST');

                break;
            case CURLOPT_PUT:
                $this->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, ($value == 1 ? 'PUT' : 'GET'));

                break;
            case CURLOPT_NOBODY:
                // Based on https://github.com/curl/curl/blob/curl-7_73_0/lib/setopt.c#L269
                $this->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, ($value == 1 ? 'HEAD' : 'GET'));

                break;
            case CURLOPT_URL:
                $this->setAttribute(TraceAttributes::URL_FULL, self::redactUrlString($value));

                break;
            case CURLOPT_USERAGENT:
                $this->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $value);

                break;
            case CURLOPT_HTTPHEADER:
                $this->headers = $value;

                break;
            case CURLOPT_HEADERFUNCTION:
                $this->originalHeaderFunction = $value;
                $this->verboseEnabled = false;

                break;
            case CURLOPT_VERBOSE:
                $this->verboseEnabled = (bool) $value;

                break;
        }
    }

    public static function redactUrlString(string $fullUrl)
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
}
