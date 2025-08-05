<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Yii\tests\Integration;

use OpenTelemetry\SemConv\TraceAttributes;
use yii\web\Application;
use yii\web\Controller;

class YiiInstrumentationTest extends AbstractTest
{
    public function test_success()
    {
        $exception = $this->runRequest('/site/index');

        // Throws on success because headers cannot be sent
        $this->assertNotNull($exception);
        $this->assertEquals('yii\web\HeadersAlreadySentException', get_class($exception));

        $attributes = $this->storage[0]->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEquals('GET SiteController.actionIndex', $this->storage[0]->getName());
        $this->assertEquals('http://example.com/site/index', $attributes->get(TraceAttributes::URL_FULL));
        $this->assertEquals('GET', $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals('SiteController.actionIndex', $attributes->get(TraceAttributes::HTTP_ROUTE));
        $this->assertEquals(200, $attributes->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals('1.1', $attributes->get(TraceAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertGreaterThan(0, $attributes->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));
    }

    public function test_non_inline_action()
    {
        $exception = $this->runRequest('/site/error');

        // This is thrown from ErrorAction that does not extend InlineAction as it is not a controller method
        $this->assertNotNull($exception);
        $this->assertEquals('yii\base\ViewNotFoundException', get_class($exception));

        $attributes = $this->storage[0]->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEquals('GET SiteController.error', $this->storage[0]->getName());
        $this->assertEquals('http://example.com/site/error', $attributes->get(TraceAttributes::URL_FULL));
        $this->assertEquals('GET', $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals('SiteController.error', $attributes->get(TraceAttributes::HTTP_ROUTE));
    }

    public function test_exception()
    {
        $exception = $this->runRequest('/site/throw');

        $this->assertNotNull($exception);
        $this->assertEquals('Threw', $exception->getMessage());

        $attributes = $this->storage[0]->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEquals('GET SiteController.actionThrow', $this->storage[0]->getName());
        $this->assertEquals('http://example.com/site/throw', $attributes->get(TraceAttributes::URL_FULL));
        $this->assertEquals('GET', $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals('SiteController.actionThrow', $attributes->get(TraceAttributes::HTTP_ROUTE));
    }

    public function test_parent()
    {
        try {
            $_SERVER['HTTP_TRACEPARENT'] = '00-0af7651916cd43dd8448eb211c80319c-b9c7c989f97918e1-01';
            $exception = $this->runRequest('/site/index');
        } finally {
            unset($_SERVER['HTTP_TRACEPARENT']);
        }

        // Throws on success because headers cannot be sent
        $this->assertNotNull($exception);
        $this->assertEquals('yii\web\HeadersAlreadySentException', get_class($exception));

        $span = $this->storage[0];
        $this->assertEquals('0af7651916cd43dd8448eb211c80319c', $span->getTraceId());
        $this->assertEquals('b9c7c989f97918e1', $span->getParentSpanId());

        $attributes = $span->getAttributes();
        $this->assertCount(1, $this->storage);
        $this->assertEquals('GET SiteController.actionIndex', $this->storage[0]->getName());
        $this->assertEquals('http://example.com/site/index', $attributes->get(TraceAttributes::URL_FULL));
        $this->assertEquals('GET', $attributes->get(TraceAttributes::HTTP_REQUEST_METHOD));
        $this->assertEquals('http', $attributes->get(TraceAttributes::URL_SCHEME));
        $this->assertEquals('SiteController.actionIndex', $attributes->get(TraceAttributes::HTTP_ROUTE));
        $this->assertEquals(200, $attributes->get(TraceAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertEquals('1.1', $attributes->get(TraceAttributes::NETWORK_PROTOCOL_VERSION));
        $this->assertGreaterThan(0, $attributes->get(TraceAttributes::HTTP_RESPONSE_BODY_SIZE));
    }

    private function runRequest($path)
    {
        $config = [
            'id' => 'basic',
            'basePath' => dirname(__DIR__),
            'bootstrap' => ['log'],
            'components' => [
                'request' => [
                    // !!! insert a secret key in the following (if it is empty) - this is required by cookie validation
                    'cookieValidationKey' => 'ybQT_avit3_11aPJfJQ6zwbdjjwjOYbs',
                ],
                'cache' => [
                    'class' => 'yii\caching\FileCache',
                ],
                'user' => [
                    'identityClass' => 'app\models\User',
                    'enableAutoLogin' => true,
                ],
                'errorHandler' => [
                    'errorAction' => 'site/error',
                ],
                'log' => [
                    'traceLevel' => 3,
                    'targets' => [
                        [
                            'class' => 'yii\log\FileTarget',
                            'levels' => ['error', 'warning'],
                        ],
                    ],
                ],
                'db' => [
                    'class' => 'yii\db\Connection',
                    'dsn' => 'mysql:host=localhost;dbname=yii2basic',
                    'username' => 'root',
                    'password' => '',
                    'charset' => 'utf8',
                ],
                'urlManager' => [
                    'enablePrettyUrl' => true,
                    'showScriptName' => false,
                    'rules' => [
                        '<controller:\w+>' => '<controller>',
                        '<controller:\w+>/<action:\w+>' => '<controller>/<action>',
                    ],
                ],
            ],
            'controllerMap' => [
                'site' => [
                    'class' => 'OpenTelemetry\Tests\Instrumentation\Yii\tests\Integration\SiteController',
                ],
            ],
            'params' => [],
        ];

        $_SERVER['SERVER_NAME'] = 'example.com';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['SCRIPT_NAME'] = '/';
        $_SERVER['SCRIPT_FILENAME'] = '/';

        $exception = null;

        try {
            (new Application($config))->run();
        } catch (\Exception $e) {
            $exception = $e;
        }

        return $exception;
    }
}

class SiteController extends Controller
{
    /**
     * @return mixed
     */
    public function actionIndex()
    {
        /** @var array{class: class-string, format: string, content: string} $config */
        $config = [
            'class' => 'yii\web\Response',
            'format' => \yii\web\Response::FORMAT_RAW,
            'content' => 'hello',
    ];

        return \Yii::createObject($config);
    }

    /** @psalm-suppress MoreSpecificReturnType */
    public function actions()
    {
        /** @psalm-suppress LessSpecificReturnStatement */
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    public function actionThrow()
    {
        throw new \Exception('Threw');
    }
}
