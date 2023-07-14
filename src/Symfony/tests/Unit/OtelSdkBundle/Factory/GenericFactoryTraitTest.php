<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Symfony\Unit\OtelSdkBundle\Factory;

use DG\BypassFinals;
use InvalidArgumentException;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Factory\GenericFactoryInterface;
use OpenTelemetry\Contrib\Symfony\OtelSdkBundle\Factory\GenericFactoryTrait;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GenericFactoryTraitTest extends TestCase
{
    private const OPTIONS = [
        'foo_bar',
        'bar_baz',
        'baz_bar',
        'foo_baz',
    ];
    private const REQUIRED = [
        'foo_bar',
    ];
    private const DEFAULTS = [
        'bar_baz' => 42,
        'baz_bar' => [],
        'foo_baz' => null,
    ];

    /**
     * @var GenericFactoryInterface
     */
    private GenericFactoryInterface $factory;
    /**
     * @var OptionsResolver|MockObject
     */
    private OptionsResolver $resolver;

    // SETUP
    public function setUp(): void
    {
        BypassFinals::enable();
    }

    private function init(): void
    {
        $this->initResolver();
        $this->initFactory();
    }

    private function initResolver(): void
    {
        $this->resolver = $this->createOptionsResolverMock();
    }

    /**
     * @psalm-suppress PossiblyInvalidArgument
     */
    private function initFactory(string $class = TestedClass::class): void
    {
        $this->factory = GenericFactoryImplementation::create(
            $class,
            $this->resolver
        );
    }

    // TESTS
    public function testCreate(): void
    {
        $this->init();

        $this->assertInstanceOf(
            GenericFactoryInterface::class,
            $this->factory
        );
    }

    /**
     * @psalm-suppress PossiblyInvalidArgument
     */
    public function testCreateWithNonExistingClass(): void
    {
        $this->initResolver();
        $class = 'NonExistingClass';
        $this->assertFalse(
            class_exists($class)
        );

        $this->expectException(InvalidArgumentException::class);
        GenericFactoryImplementation::create(
            $class,
            $this->resolver
        );
    }

    /** @noinspection UnnecessaryAssertionInspection */
    public function testGetOptionsResolver(): void
    {
        $this->init();

        $this->assertInstanceOf(
            OptionsResolver::class,
            $this->factory->getOptionsResolver()
        );
    }

    public function testGetReflectionClass(): void
    {
        $this->init();

        $this->assertSame(
            TestedClass::class,
            $this->factory->getReflectionClass()->getName()
        );
    }

    public function testGetClass(): void
    {
        $this->init();

        $this->assertSame(
            TestedClass::class,
            $this->factory->getClass()
        );
    }

    public function testGetOptions(): void
    {
        $this->init();

        $this->assertSame(
            self::OPTIONS,
            $this->factory->getOptions()
        );
    }

    public function testGetRequiredOptions(): void
    {
        $this->init();

        $this->assertSame(
            self::REQUIRED,
            $this->factory->getRequiredOptions()
        );
    }

    public function testGetDefaults(): void
    {
        $this->init();

        $this->assertEquals(
            self::DEFAULTS,
            $this->factory->getDefaults()
        );
    }

    public function testSetDefault(): void
    {
        $this->init();

        $option = 'foo_bar';
        $value = 'foo';
        $defaults = self::DEFAULTS;
        $defaults[$option] = $value;

        $this->factory->setDefault($option, $value);

        $this->assertEquals(
            $defaults,
            $this->factory->getDefaults()
        );
    }

    public function testSetDefaults(): void
    {
        $this->init();

        $option = 'foo_bar';
        $value = 'foo';
        $defaults = self::DEFAULTS;
        $defaults[$option] = $value;

        $this->factory->setDefaults([$option => $value]);

        $this->assertEquals(
            $defaults,
            $this->factory->getDefaults()
        );
    }

    public function testBuildWithDefaults(): void
    {
        $option = 'foo_bar';
        $value = 'foo';
        $defaults = self::DEFAULTS;
        $defaults[$option] = $value;

        $this->initResolver();
        /** @psalm-suppress PossiblyUndefinedMethod **/
        $this->resolver->expects($this->once())
            ->method('resolve')
            ->willReturn($defaults);

        $this->initFactory();
        $this->factory->setDefaults([$option => $value]);

        $obj = $this->factory->build();

        $this->assertInstanceOf(
            TestedClass::class,
            $obj
        );

        $this->assertSame(
            $defaults['foo_bar'],
            $obj->getFooBar()
        );
        $this->assertSame(
            self::DEFAULTS['bar_baz'],
            $obj->getBarBaz()
        );
        $this->assertSame(
            self::DEFAULTS['baz_bar'],
            $obj->getBazBar()
        );
        $this->assertSame(
            self::DEFAULTS['foo_baz'],
            $obj->getFooBaz()
        );
    }

    public function testBuildWithOptions(): void
    {
        $options = [
            'foo_bar' => 'baz',
            'bar_baz' => 123,
            'baz_bar' => ['foo' => 'bar'],
            'foo_baz' => new stdClass(),
        ];

        $this->initResolver();
        /** @psalm-suppress PossiblyUndefinedMethod **/
        $this->resolver->expects($this->once())
            ->method('resolve')
            ->willReturn($options);

        $this->initFactory();

        $obj = $this->factory->build($options);

        $this->assertInstanceOf(
            TestedClass::class,
            $obj
        );
        $this->assertSame(
            $options['foo_bar'],
            $obj->getFooBar()
        );
        $this->assertSame(
            $options['bar_baz'],
            $obj->getBarBaz()
        );
        $this->assertSame(
            $options['baz_bar'],
            $obj->getBazBar()
        );
        $this->assertSame(
            $options['foo_baz'],
            $obj->getFooBaz()
        );
    }

    /**
     * @psalm-suppress PossiblyInvalidArgument
     */
    public function testBuildClassWithoutConstructor(): void
    {
        $this->initResolver();

        $factory = new GenericFactoryImplementation(
            stdClass::class,
            $this->resolver
        );

        $this->assertInstanceOf(
            stdClass::class,
            $factory->build()
        );
    }

    public function testBuildWithDefaultNull(): void
    {
        $options = ['foo_bar' => 'baz'];

        $this->initResolver();
        /** @psalm-suppress PossiblyUndefinedMethod **/
        $this->resolver->expects($this->once())
            ->method('resolve')
            ->willReturn($options);

        $this->initFactory(TestedDefaultNullClass::class);

        $this->assertEquals(
            ['foo_baz' => null],
            $this->factory->getDefaults()
        );

        $this->assertInstanceOf(
            TestedDefaultNullClass::class,
            $obj = $this->factory->build($options)
        );
    }

    // UTIL

    /**
     * @return OptionsResolver|MockObject
     * @psalm-suppress PossiblyInvalidArgument
     * @psalm-suppress MismatchingDocblockReturnType
     */
    private function createOptionsResolverMock(): OptionsResolver
    {
        return $this->createMock(
            OptionsResolver::class
        );
    }
}

class GenericFactoryImplementation implements GenericFactoryInterface
{
    use GenericFactoryTrait;

    public function build(array $options = []): object
    {
        return $this->doBuild($options);
    }
}

class TestedClass
{
    public const DEFAULT_BAR_BAZ = 42;

    private string $fooBar;
    private int $barBaz;
    private array $bazBar;
    private ?stdClass $fooBaz = null;

    public function __construct(string $fooBar, int $barBaz = self::DEFAULT_BAR_BAZ, array $bazBar = [], ?stdClass $fooBaz = null)
    {
        $this->fooBar = $fooBar;
        $this->barBaz = $barBaz;
        $this->bazBar = $bazBar;
        $this->fooBaz = $fooBaz;
    }

    /**
     * @return string
     */
    public function getFooBar(): string
    {
        return $this->fooBar;
    }

    /**
     * @return int
     */
    public function getBarBaz(): int
    {
        return $this->barBaz;
    }

    /**
     * @return array
     */
    public function getBazBar(): array
    {
        return $this->bazBar;
    }

    /**
     * @return stdClass|null
     */
    public function getFooBaz(): ?stdClass
    {
        return $this->fooBaz;
    }
}

class TestedDefaultNullClass
{
    // untyped properties
    private $fooBar;
    private $fooBaz;

    public function __construct(string $fooBar, stdClass $fooBaz = null)
    {
        $this->fooBar = $fooBar;
        $this->fooBaz = $fooBaz;
    }

    /**
     * @return string
     */
    public function getFooBar(): string
    {
        return $this->fooBar;
    }

    /**
     * @return stdClass|null
     */
    public function getFooBaz(): ?stdClass
    {
        return $this->fooBaz;
    }
}
