<?php

declare(strict_types=1);

namespace tests\Libero\ContentStore;

use FluentDOM;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use League\Flysystem\AdapterInterface;
use League\Flysystem\FilesystemInterface;
use PHPUnit\Xpath\Assert as XpathAssertions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ContentServiceTest extends KernelTestCase
{
    private const ARTICLES_PATH = __DIR__.'/fixtures';

    use XpathAssertions;

    /**
     * @test
     */
    public function it_pings() : void
    {
        self::bootKernel();

        $response = self::$kernel->handle(Request::create('/ping'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('pong', $response->getContent());
    }

    /**
     * @test
     */
    public function it_lists_items() : void
    {
        self::bootKernel();

        $response = self::$kernel->handle(Request::create('/items'));

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

        $response = $this->handle(Request::create('/items/article1/versions/latest'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertXmlStringEqualsXmlFile(self::ARTICLES_PATH.'/article1/2.xml', $response->getContent());
    }

    /**
     * @test
     */
    public function it_gets_an_item() : void
    {
        self::bootKernel();

        $response = $this->handle(Request::create('/items/article1/versions/1'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertXmlStringEqualsXmlFile(self::ARTICLES_PATH.'/article1/1.xml', $response->getContent());
    }

    /**
     * @test
     * @backupGlobals enabled
     */
    public function it_adds_an_item() : void
    {
        $_ENV['ASSETS_ORIGIN_WHITELIST'] = '.+';
        $_ENV['ASSETS_PUBLIC_URI'] = 'http://public-assets/path';

        self::bootKernel();

        self::mockHttpResponse(
            new Psr7Request('GET', 'http://origin-assets/new-article/assets/figure1.jpg'),
            new Psr7Response(Response::HTTP_OK, ['Content-Type' => 'image/jpeg;foo=bar'], 'figure1')
        );

        $request = Request::create(
            '/items/new-article/versions/1',
            'PUT',
            [],
            [],
            [],
            [],
            <<<XML
<item xmlns="http://libero.pub">
    <meta>
        <id>new-article</id>
        <service>articles</service>
    </meta>
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink"
        xml:base="http://origin-assets/new-article">
        <front>
            <article-meta>
                <title-group>
                    <article-title>Article title</article-title>
                </title-group>
            </article-meta>
        </front>
        <body>
            <sec>
                <title>Introduction</title>
                <fig>
                    <graphic xlink:href="assets/figure1.jpg"/>
                </fig>
            </sec>
        </body>
    </article>
</item>
XML
        );

        $response = $this->handle($request);

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        $response = $this->handle(Request::create('/items/new-article/versions/1'));

        $this->assertXmlStringEqualsXmlString(
            <<<XML
<item xmlns="http://libero.pub">
    <meta>
        <id>new-article</id>
        <service>articles</service>
    </meta>
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink"
        xml:base="http://origin-assets/new-article">
        <front>
            <article-meta>
                <title-group>
                    <article-title>Article title</article-title>
                </title-group>
            </article-meta>
        </front>
        <body>
            <sec>
                <title>Introduction</title>
                <fig>
                    <graphic mimetype="image" mime-subtype="jpeg"
                        xlink:href="http://public-assets/path/new-article/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg"/>
                </fig>
            </sec>
        </body>
    </article>
</item>
XML
            ,
            $response->getContent()
        );

        /** @var FilesystemInterface $flysystem */
        $flysystem = self::$container->get('oneup_flysystem.assets_filesystem');
        $this->assertTrue($flysystem->has('new-article/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg'));
        $this->assertSame('figure1', $flysystem->read('new-article/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg'));
        $this->assertSame(
            AdapterInterface::VISIBILITY_PUBLIC,
            $flysystem->getVisibility('new-article/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg')
        );
        $this->assertSame(
            'image/jpeg',
            $flysystem->getMimetype('new-article/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg')
        );
    }

    /**
     * @test
     */
    public function it_validates_items() : void
    {
        self::bootKernel();

        $request = Request::create(
            '/items/new-article/versions/1',
            'PUT',
            [],
            [],
            [],
            [],
            <<<XML
<item xmlns="http://libero.pub">
    <meta>
        <id>new-article</id>
        <service>not-articles</service>
    </meta>
</item>
XML
        );

        $response = $this->handle($request);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }
}
