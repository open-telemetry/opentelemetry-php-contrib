<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\OpenAIPHP\tests\Integration;

use ArrayObject;
use Http\Client\Exception\NetworkException;
use Nyholm\Psr7\Response;
use OpenAI\Client;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Factory;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Contrib\Instrumentation\OpenAIPHP\OpenAIAttributes;
use OpenTelemetry\Contrib\Instrumentation\OpenAIPHP\OpenAIPHPInstrumentation;
use OpenTelemetry\SDK\Trace\Event;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

class OpenAIPHPInstrumentationTest extends TestCase
{
    private ScopeInterface $scope;
    private ArrayObject $storage;

    public function setUp(): void
    {
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor(
                new InMemoryExporter($this->storage)
            )
        );

        $this->scope = Configurator::create()
            ->withTracerProvider($tracerProvider)
            ->withPropagator(TraceContextPropagator::getInstance())
            ->activate();
    }

    public function tearDown(): void
    {
        $this->scope->detach();
    }

    private function loadFile(string $file): string
    {
        return file_get_contents(__DIR__ . '/../resources/' . $file . '.json');
    }

    private function createClient(string $fixture): Client
    {
        $responseJson = $this->loadFile($fixture);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willReturn(new Response(200, ['content-type' => 'application/json'], $responseJson));

        $factory = new Factory();

        return $factory
            ->withHttpClient($httpClient)
            ->make();
    }

    public function dataProvider(): array
    {
        return [
            'audio->transcribe' => ['audio', 'transcribe', [['model' => 'whisper-1']], 'audio-transcribe', [OpenAIAttributes::OPENAI_MODEL => 'whisper-1', OpenAIAttributes::OPENAI_RESOURCE => 'audio/transcribe']],
            'audio->translate' => ['audio', 'translate', [[]], 'audio-translate', []],

            'completions->create' => ['completions', 'create', [['model' => 'gpt-3.5-turbo-instruct']], 'completions-create', [OpenAIAttributes::OPENAI_MODEL => 'gpt-3.5-turbo-instruct']],

            'chat->create' => ['chat', 'create', [['messages' => [], 'model' => 'gpt-3.5-turbo-instruct']], 'chat-create', [OpenAIAttributes::OPENAI_MODEL => 'gpt-3.5-turbo-instruct', OpenAIAttributes::OPENAI_USAGE_TOTAL_TOKENS => 21, OpenAIAttributes::OPENAI_USAGE_COMPLETION_TOKENS => 12, OpenAIAttributes::OPENAI_USAGE_PROMPT_TOKENS => 9]],

            'embeddings->create' => ['embeddings', 'create', [['input' => 'The food was delicious and the waiter...', 'model' => 'text-embedding-ada-002', 'encoding_format' => 'float']], 'embeddings-create', []],

            'images->create' => ['images', 'create', [['model' => 'dall-e-3']], 'images-create', [OpenAIAttributes::OPENAI_MODEL => 'dall-e-3']],
            'images->edit' => ['images', 'create', [['model' => 'dall-e-2']], 'images-edit', [OpenAIAttributes::OPENAI_MODEL => 'dall-e-2']],
            'images->variation' => ['images', 'variation', [['model' => 'dall-e-2']], 'images-variation', [OpenAIAttributes::OPENAI_MODEL => 'dall-e-2']],

            'models->list' => ['models', 'list', [], 'models-list', []],
            'models->retrieve' => ['models', 'retrieve', ['gpt-3.5-turbo-instruct'], 'models-retrieve', []],
            'models->delete' => ['models', 'delete', ['gpt-3.5-turbo-instruct'], 'models-delete', []],
        ];
    }

    /**
     * @dataProvider dataProvider
     */
    public function test_openai_operation($api, $operation, $args, $fixture, array $spanAttributes): void
    {
        $client = $this->createClient($fixture);
        $response = $client->$api()->$operation(...$args);

        $this->assertCount(1, $this->storage);

        /** @var ImmutableSpan $span */
        $span = $this->storage[0];

        $this->assertSame('openai ' . $api . '/' . $operation, $span->getName());

        foreach ($spanAttributes as $key => $value) {
            $this->assertSame($value, $span->getAttributes()->get($key));
        }
    }

    public function test_will_set_status()
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('sendRequest')
            ->willThrowException(new NetworkException('Request failed', $this->createMock(RequestInterface::class)));

        $factory = new Factory();

        $client = $factory
            ->withHttpClient($httpClient)
            ->make();

        try {
            $response = $client->completions()->create([
                'model' => 'gpt-3.5-turbo-instruct',
                'prompt' => 'Say this is a test',
                'max_tokens' => 7,
                'temperature' => 0,
            ]);
        } catch (\Throwable $th) {
            $this->assertInstanceOf(TransporterException::class, $th);
        } finally {
            $this->assertCount(1, $this->storage);

            /** @var ImmutableSpan $span */
            $span = $this->storage[0];

            $this->assertSame('openai completions/create', $span->getName());
            $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            $this->assertSame('Request failed', $span->getStatus()->getDescription());

            $this->assertCount(1, $span->getEvents());

            /** @var Event $event */
            $event = $span->getEvents()[0];
            $this->assertSame('exception', $event->getName());
            $this->assertSame('Request failed', $event->getAttributes()->get('exception.message'));
        }
    }

    public function test_can_register()
    {
        $this->expectNotToPerformAssertions();

        OpenAIPHPInstrumentation::register();
    }
}
