<?php

declare(strict_types=1);

namespace tests\Libero\ContentStore;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as BaseKernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function ob_get_clean;
use function ob_start;

abstract class KernelTestCase extends BaseKernelTestCase
{
    final protected function handle(Request $request) : Response
    {
        ob_start();
        $response = self::$kernel->handle($request);
        $content = ob_get_clean();

        if (!$response instanceof StreamedResponse) {
            return $response;
        }

        return new Response($content, $response->getStatusCode(), $response->headers->all());
    }
}
