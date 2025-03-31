<?php

declare(strict_types=1);

namespace Killerwolf\MCPProfilerBundle\Tests;

use Killerwolf\MCPProfilerBundle\MCPProfilerBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class MCPProfilerBundleTest extends TestCase
{
    public function testBundleInstance(): void
    {
        $bundle = new MCPProfilerBundle();
        $this->assertInstanceOf(Bundle::class, $bundle);
        $this->assertInstanceOf(MCPProfilerBundle::class, $bundle);
    }

    // Add more tests here later
}
