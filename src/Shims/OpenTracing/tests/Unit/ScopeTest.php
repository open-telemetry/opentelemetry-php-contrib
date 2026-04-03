<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Shim\OpenTracing\Unit;

use OpenTelemetry\Contrib\Shim\OpenTracing\ScopeManager;
use OpenTracing as API;
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase
{
    public function test_close_with_deeply_nested_closed_scopes(): void
    {
        $scopeManager = new ScopeManager();
        $span = $this->createMock(API\Span::class);

        // Create 3 scopes: A -> B -> C
        $scopeA = $scopeManager->activate($span, false);
        $scopeB = $scopeManager->activate($span, false);
        $scopeC = $scopeManager->activate($span, false);

        // Close B out-of-order (it becomes closed but is still in the chain)
        // We need to close B by closing A first in scope chain terms
        // Actually, close B while C is active (B is in toRestore chain)
        // Close C first - this restores B
        $scopeC->close();
        $this->assertSame($scopeB, $scopeManager->getActive());

        // Close B - this restores A
        $scopeB->close();
        $this->assertSame($scopeA, $scopeManager->getActive());

        // Close A - toRestore is null since A was the first scope
        $scopeA->close();
        $this->assertNull($scopeManager->getActive());
    }

    public function test_close_restores_through_closed_intermediate_scopes(): void
    {
        $scopeManager = new ScopeManager();
        $span = $this->createMock(API\Span::class);

        // Create scope A (root, no toRestore)
        $scopeA = $scopeManager->activate($span, false);
        // Create scope B (toRestore = A)
        $scopeB = $scopeManager->activate($span, false);
        // Create scope C (toRestore = B)
        $scopeC = $scopeManager->activate($span, false);

        // Close B while C is still active (out of order)
        // Manually close B - but B is not the active scope, so it just marks as closed
        $scopeB->close();
        // B is closed, but C is still active
        $this->assertSame($scopeC, $scopeManager->getActive());

        // Now close C - it should skip the closed B and restore A
        $scopeC->close();
        $this->assertSame($scopeA, $scopeManager->getActive());

        // Close A
        $scopeA->close();
        $this->assertNull($scopeManager->getActive());
    }

    public function test_close_through_closed_chain_to_null(): void
    {
        $scopeManager = new ScopeManager();
        $span = $this->createMock(API\Span::class);

        // Create scope A (root)
        $scopeA = $scopeManager->activate($span, false);
        // Create scope B (toRestore = A)
        $scopeB = $scopeManager->activate($span, false);

        // Close A out of order
        $scopeA->close();
        // A is marked closed but B is still active

        // Close B - toRestore is A which is closed, A's toRestore is null
        // This should hit the null path (lines 81-84)
        $scopeB->close();
        $this->assertNull($scopeManager->getActive());
    }

    public function test_double_close_is_noop(): void
    {
        $scopeManager = new ScopeManager();
        $span = $this->createMock(API\Span::class);

        $scope = $scopeManager->activate($span, false);
        $scope->close();
        $scope->close(); // Should be no-op
        $this->assertNull($scopeManager->getActive());
    }
}
