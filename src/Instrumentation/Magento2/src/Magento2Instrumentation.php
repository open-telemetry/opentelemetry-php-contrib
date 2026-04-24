<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Magento2;

use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use Throwable;

// @phan-file-suppress PhanUndeclaredClassReference
// @phan-file-suppress PhanUndeclaredTypeParameter
final class Magento2Instrumentation
{
    public const NAME = 'magento2';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.magento2',
            null,
            'https://opentelemetry.io/schemas/1.32.0',
        );

        if (class_exists(\Magento\Framework\App\FrontController::class) && class_exists(\Magento\Framework\App\ResponseInterface::class)) {
            /** @psalm-suppress UndefinedClass */
            hook(
                \Magento\Framework\App\FrontController::class,
                'dispatch',
                /** @psalm-suppress UndefinedClass */
                pre: static function (\Magento\Framework\App\FrontController $frontController, array $params, string $class, string $function, ?string $filename, ?int $lineno) use ($instrumentation) {
                    if (interface_exists(\Magento\Framework\App\RequestInterface::class)) {
                        //                        $requestInterface = null;
                        //                        if (isset($params[0]) && is_a($params[0], \Magento\Framework\App\RequestInterface::class)) {
                        //                            $requestInterface = $params[0];
                        //                        }
                        //
                        //                        $moduleName = 'unknown';
                        //                        if (is_object($requestInterface) && method_exists($requestInterface, 'getModuleName')) {
                        //                            /** @phan-suppress-next-line PhanUndeclaredClassMethod */
                        //                            $moduleNameValue = $requestInterface->getModuleName();
                        //                            if (is_string($moduleNameValue) && $moduleNameValue !== '') {
                        //                                $moduleName = $moduleNameValue;
                        //                            }
                        //                        }

                        $builder = $instrumentation->tracer()
                            ->spanBuilder('dispatch')
                            ->setSpanKind(SpanKind::KIND_SERVER)
                            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                            ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                            ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                        $parent = Context::getCurrent();
                        //                        if ($requestInterface) {
                        //                            // $parent = Globals::propagator()->extract($requestInterface->getHeaders());
                        //                            $span = $builder
                        ////                                ->setParent($parent)
                        ////                                ->setAttribute(UrlAttributes::URL_FULL, $request->getUri()->__toString())
                        ////                                ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ////                                ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                        ////                                ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                        ////                                ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                        ////                                ->setAttribute(ServerAttributes::SERVER_PORT, $request->getUri()->getPort())
                        ////                                ->setAttribute(UrlAttributes::URL_SCHEME, $request->getUri()->getScheme())
                        ////                                ->setAttribute(UrlAttributes::URL_PATH, $request->getUri()->getPath())
                        //                                ->startSpan();
                        //                            // $request = $request->withAttribute(SpanInterface::class, $span);
                        //                        } else {
                        $span = $builder->startSpan();
                        // }
                        Context::storage()->attach($span->storeInContext($parent));
                    }
                },
                /** @psalm-suppress UndefinedClass */
                post: static function (\Magento\Framework\App\FrontController $frontController, array $params, \Magento\Framework\App\ResponseInterface $response, ?Throwable $exception) {
                    $scope = Context::storage()->scope();
                    if (!$scope) {
                        return;
                    }
                    $scope->detach();
                    $span = Span::fromContext($scope->context());
                    $span->end();
                }
            );
        }
    }
}
