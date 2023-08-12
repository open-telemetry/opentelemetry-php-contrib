<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\MongoDB\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\MongoDB\MongoDBCollectionExtractor;
use PHPUnit\Framework\TestCase;

class MongoDBCollectionExtractorTest extends TestCase
{
    public function test_extract_collection_name(): void
    {
        self::assertSame('coll', MongoDBCollectionExtractor::extract((object) ['aggregate' => 'coll', 'find' => 'coll2']));
    }

    public function test_extract_collection_name_getMore(): void
    {
        self::assertSame('coll', MongoDBCollectionExtractor::extract((object) ['getMore' => ['getMore' => 123, 'collection' => 'coll', 'batchSize' => 10], 'find' => 'coll2']));
    }
}
