<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;
use Psr\Http\Message\ServerRequestInterface;

trait CakeHookTrait
{
    private static CakeHook $instance;

    private bool $isRoot;

    protected function __construct(
        protected CachedInstrumentation $instrumentation,
    ) {
    }

    abstract public function instrument(): void;

    public static function hook(CachedInstrumentation $instrumentation): CakeHook
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!isset(self::$instance)) {
            /** @phan-suppress-next-line PhanTypeInstantiateTraitStaticOrSelf,PhanTypeMismatchPropertyReal */
            self::$instance = new self($instrumentation);
            self::$instance->instrument();
        }

        return self::$instance;
    }

    /**
     * @param ServerRequestInterface|null $request
     * @param string $class
     * @param string $function
     * @param string|null $filename
     * @param int|null $lineno
     * @return mixed
     */
    protected function buildSpan(?ServerRequestInterface $request, string $class, string $function, ?string $filename, ?int $lineno): mixed
    {
        $root = $request
            ? $request->getAttribute(SpanInterface::class)
            : \OpenTelemetry\API\Trace\Span::getCurrent();
        $builder = $this->instrumentation->tracer()->spanBuilder(
            $root
                ? sprintf('%s::%s', $class, $function)
                : sprintf('%s', $request?->getMethod() ?? 'unknown')
        )
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute(TraceAttributes::CODE_FUNCTION, $function)
            ->setAttribute(TraceAttributes::CODE_NAMESPACE, $class)
            ->setAttribute(TraceAttributes::CODE_FILEPATH, $filename)
            ->setAttribute(TraceAttributes::CODE_LINENO, $lineno);
        $parent = Context::getCurrent();
        if (!$root && $request) {
            $this->isRoot = true;
            //create http root span
            $parent = Globals::propagator()->extract($request->getHeaders());
            $span = $builder
                ->setParent($parent)
                ->setAttribute(TraceAttributes::URL_FULL, $request->getUri()->__toString())
                ->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                ->setAttribute(TraceAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                ->setAttribute(TraceAttributes::URL_SCHEME, $request->getUri()->getScheme())
                ->setAttribute(TraceAttributes::URL_PATH, $request->getUri()->getPath())
                ->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                ->setAttribute(TraceAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                ->setAttribute(TraceAttributes::SERVER_PORT, $request->getUri()->getPort())
                ->startSpan();
            $request = $request->withAttribute(SpanInterface::class, $span);
        } else {
            $this->isRoot = false;
            $span = $builder->setSpanKind(SpanKind::KIND_INTERNAL)->startSpan();
        }
        Context::storage()->attach($span->storeInContext($parent));

        return $request;
    }

    protected function isRoot(): bool
    {
        return $this->isRoot;
    }
}
