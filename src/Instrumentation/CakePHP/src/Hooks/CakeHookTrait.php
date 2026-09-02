<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\CakePHP\Hooks;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
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
     * @psalm-suppress ArgumentTypeCoercion
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
            ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
            ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
            ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
        $parent = Context::getCurrent();
        if (!$root && $request) {
            $this->isRoot = true;
            //create http root span
            $parent = Globals::propagator()->extract($request->getHeaders());
            $span = $builder
                ->setParent($parent)
                ->setAttribute(UrlAttributes::URL_FULL, $request->getUri()->__toString())
                ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->getHeaderLine('Content-Length'))
                ->setAttribute(UrlAttributes::URL_SCHEME, $request->getUri()->getScheme())
                ->setAttribute(UrlAttributes::URL_PATH, $request->getUri()->getPath())
                ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->getHeaderLine('User-Agent'))
                ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getUri()->getHost())
                ->setAttribute(ServerAttributes::SERVER_PORT, $request->getUri()->getPort())
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
