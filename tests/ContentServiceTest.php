<?php

declare(strict_types=1);

namespace tests\Libero\ContentStore;

use FluentDOM;
use PHPUnit\Xpath\Assert as XpathAssertions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use function ob_get_clean;
use function ob_start;

/**
 * @backupGlobals enabled
 */
final class ContentServiceTest extends KernelTestCase
{
    private const ARTICLES_PATH = __DIR__.'/fixtures';

    use XpathAssertions;

    /**
     * @before
     */
    public function setUpFixtures() : void
    {
        $_SERVER['ARTICLES_PATH'] = self::ARTICLES_PATH;
    }

    /**
     * @test
     */
    public function it_pings() : void
    {
        self::bootKernel();

        $response = self::$kernel->handle(Request::create('/articles/ping'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('pong', $response->getContent());
    }

    /**
     * @test
     */
    public function it_lists_items() : void
    {
        self::bootKernel();

        $response = self::$kernel->handle(Request::create('/articles/items'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));

        $dom = FluentDOM::load($response->getContent());

        $this->assertXpathCount(2, '/libero:item-list/libero:item-ref', $dom, ['libero' => 'http://libero.pub']);
    }

    /**
     * @test
     */
    public function it_gets_an_item_version() : void
    {
        self::bootKernel();

        $response = $this->handle(Request::create('/articles/items/article1/versions/latest'), $content);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertXmlStringEqualsXmlFile(self::ARTICLES_PATH.'/article1/2.xml', $content);
    }

    /**
     * @test
     */
    public function it_gets_an_item() : void
    {
        self::bootKernel();

        $response = $this->handle(Request::create('/articles/items/article1/versions/1'), $content);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertXmlStringEqualsXmlFile(self::ARTICLES_PATH.'/article1/1.xml', $content);
    }

    private function handle(Request $request, &$content) : Response
    {
        ob_start();
        $response = self::$kernel->handle($request);
        $content = ob_get_clean();

        return $response;
    }
}
