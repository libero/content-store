<?php

declare(strict_types=1);

namespace tests\Libero\ContentStore;

use Doctrine\DBAL\Connection;
use Libero\ContentApiBundle\Adapter\DoctrineItems;
use Libero\ContentApiBundle\Model\ItemId;
use Libero\ContentApiBundle\Model\Items;
use Libero\ContentApiBundle\Model\ItemVersion;
use Libero\ContentApiBundle\Model\ItemVersionNumber;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase as BaseKernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use function array_map;
use function basename;
use function iterator_to_array;
use function md5;
use function ob_get_clean;
use function ob_start;
use function usort;

abstract class KernelTestCase extends BaseKernelTestCase
{
    protected static function bootKernel(array $options = [])
    {
        parent::bootKernel($options);

        /** @var Connection $connection */
        $connection = self::$container->get('doctrine.dbal.default_connection');
        /** @var DoctrineItems $items */
        $items = self::$container->get('libero.content_store.items');

        foreach ($items->getSchema()->toSql($connection->getDatabasePlatform()) as $query) {
            $connection->exec($query);
        }

        self::loadFixtures($items);

        return self::$kernel;
    }

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

    private static function loadFixtures(Items $items) : void
    {
        $fixtures = array_map(
            function (SplFileInfo $fixture) : ItemVersion {
                return new ItemVersion(
                    ItemId::fromString(basename($fixture->getPath())),
                    ItemVersionNumber::fromString($fixture->getBasename('.xml')),
                    stream_from_string($fixture->getContents()),
                    md5($fixture->getPathname())
                );
            },
            iterator_to_array((new Finder())->files()->in(__DIR__.'/fixtures'))
        );

        usort(
            $fixtures,
            function (ItemVersion $a, ItemVersion $b) : int {
                return $a->getVersion() <=> $b->getVersion();
            }
        );

        foreach ($fixtures as $fixture) {
            $items->add($fixture);
        }
    }
}
