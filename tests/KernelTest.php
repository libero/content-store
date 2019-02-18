<?php

declare(strict_types=1);

namespace tests\Libero\ContentStore;

use Libero\ContentStore\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class KernelTest extends TestCase
{
    /**
     * @test
     */
    public function it_is_a_kernel() : void
    {
        $kernel = new Kernel('dev', false);

        $this->assertInstanceOf(HttpKernelInterface::class, $kernel);
    }
}
