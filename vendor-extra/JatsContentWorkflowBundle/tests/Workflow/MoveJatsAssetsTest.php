<?php

declare(strict_types=1);

namespace tests\Libero\JatsContentWorkflowBundle\Workflow;

use Csa\GuzzleHttp\Middleware\Cache\Adapter\MockStorageAdapter;
use Csa\GuzzleHttp\Middleware\Cache\MockMiddleware;
use Exception;
use FluentDOM;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use League\Flysystem\Memory\MemoryAdapter;
use Libero\ContentApiBundle\Model\ItemId;
use Libero\ContentApiBundle\Model\ItemVersionNumber;
use Libero\ContentApiBundle\Model\PutTask;
use Libero\JatsContentWorkflowBundle\Exception\AssetDeployFailed;
use Libero\JatsContentWorkflowBundle\Exception\AssetLoadFailed;
use Libero\JatsContentWorkflowBundle\Exception\InvalidContentType;
use Libero\JatsContentWorkflowBundle\Exception\UnknownContentType;
use Libero\JatsContentWorkflowBundle\Workflow\MoveJatsAssets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\Event;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Twistor\FlysystemStreamWrapper;
use function array_filter;

final class MoveJatsAssetsTest extends TestCase
{
    /** @var ClientInterface */
    private $client;
    /** @var Filesystem */
    private $filesystem;
    /** @var MockStorageAdapter */
    private $mock;

    /**
     * @before
     */
    public function setupClient() : void
    {
        $filesystem = new Filesystem(new MemoryAdapter());
        if (!FlysystemStreamWrapper::register('guzzle-cache', $filesystem)) {
            throw new Exception('Could not register stream wrapper');
        }

        $this->mock = new MockStorageAdapter('guzzle-cache://', ['User-Agent']);
        $stack = HandlerStack::create(new MockHandler());
        $stack->push(new MockMiddleware($this->mock, 'replay'));

        $this->client = new Client(['handler' => $stack]);
    }

    /**
     * @before
     */
    public function setupFilesystem() : void
    {
        $this->filesystem = new Filesystem(new MemoryAdapter());
    }

    /**
     * @after
     */
    public function unregisterStreamWrapper() : void
    {
        FlysystemStreamWrapper::unregister('guzzle-cache');
    }

    /**
     * @test
     */
    public function it_does_nothing_if_it_is_not_jats() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load('<item xmlns="http://libero.pub"/>');

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), clone $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $mover->onManipulate($event);

        $this->assertXmlStringEqualsXmlString($document->saveXML(), $task->getDocument()->saveXML());
        $this->assertEmpty($this->filesystem->listContents());
    }

    /**
     * @test
     */
    public function it_handles_assets() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub" xml:base="http://origin-assets/">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <front>
            <article-meta>
                <self-uri xlink:href="/assets/article.pdf"/>
            </article-meta>
        </front>
        <body>
            <graphic xlink:href="assets/figure1.jpg"/>
            <sec>
                <fig>
                    <graphic xlink:href="assets/figure2.jpg"/>
                    <media mimetype="video" mime-subtype="avi" xlink:href="assets/figure2">
                        <caption>
                            <p>
                                <fig-group>
                                    <alternatives>
                                        <supplementary-material mimetype="foo" mime-subtype="bar" 
                                            xlink:href="assets/something/figure2.txt"/>
                                    </alternatives>
                                </fig-group>
                            </p>
                        </caption>
                    </media>
                    <graphic xlink:href="/assets/figure2.pdf"/>
                    <alternatives>
                        <graphic xlink:href="http://origin-assets/assets/figure2.jpg"/>
                    </alternatives>
                </fig>
            </sec>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/article.pdf'),
            new Response(200, ['Content-Type' => 'application/pdf'], 'article pdf')
        );

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure1.jpg'),
            new Response(200, ['Content-Type' => 'image/jpeg;foo=bar'], 'figure1')
        );

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure2.jpg'),
            new Response(200, ['Content-Type' => 'image/jpeg;foo=bar'], 'figure2 jpeg')
        );

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure2'),
            new Response(200, ['Content-Type' => 'video/avi'], 'figure2 avi')
        );

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/something/figure2.txt'),
            new Response(200, ['Content-Type' => 'application/xml'], 'figure2 xml')
        );

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure2.pdf'),
            new Response(200, ['Content-Type' => 'application/pdf'], 'figure2 pdf')
        );

        $mover->onManipulate($event);

        $basePath = 'http://public-assets/path/id/v1';

        $expected = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub" xml:base="http://origin-assets/">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <front>
            <article-meta>
                <self-uri xlink:href="{$basePath}/dcd99c5055598bed7350ec58a4153d5d.pdf"/>
            </article-meta>
        </front>
        <body>
            <graphic xlink:href="{$basePath}/879f77a11b0649cb8af511fa5d6e4a7e.jpeg"
                mimetype="image" mime-subtype="jpeg"/>
            <sec>
                <fig>
                    <graphic xlink:href="{$basePath}/249c9efda2fc00821bb65c8ca1c89635.jpeg"
                        mimetype="image" mime-subtype="jpeg"/>
                    <media xlink:href="{$basePath}/f1aa6a59b56406414301af35cf1a1178"
                        mimetype="video" mime-subtype="avi">
                        <caption>
                            <p>
                                <fig-group>
                                    <alternatives>
                                        <supplementary-material mimetype="application" mime-subtype="xml" 
                                            xlink:href="{$basePath}/3f67ade33288e5f9a9f54b8bac3f3042.xml"/>
                                    </alternatives>
                                </fig-group>
                            </p>
                        </caption>
                    </media>
                    <graphic xlink:href="{$basePath}/601e38e045a4d1b50350ecf57e4e8630.pdf"
                        mimetype="application" mime-subtype="pdf"/>
                    <alternatives>
                        <graphic xlink:href="{$basePath}/249c9efda2fc00821bb65c8ca1c89635.jpeg"
                            mimetype="image" mime-subtype="jpeg"/>
                    </alternatives>
                </fig>
            </sec>
        </body>
    </article>
</item>
XML
        );

        $files = array_filter(
            $this->filesystem->listContents('', true),
            static function (array $item) : bool {
                return 'file' === $item['type'];
            }
        );

        $this->assertXmlStringEqualsXmlString($expected, $task->getDocument());
        $this->assertCount(6, $files);
    }

    /**
     * @test
     */
    public function it_sets_metadata() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub" xml:base="http://origin-assets/">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="assets/figure1.jpg"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure1.jpg'),
            new Response(200, ['Content-Type' => 'image/jpeg;foo=bar'], 'figure1')
        );

        $mover->onManipulate($event);

        $path = 'id/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg';

        $this->assertSame('figure1', $this->filesystem->read($path));
        $this->assertSame(AdapterInterface::VISIBILITY_PUBLIC, $this->filesystem->getVisibility($path));
        $this->assertSame('image/jpeg', $this->filesystem->getMimetype($path));
    }

    /**
     * @test
     */
    public function it_checks_the_origin_uri() : void
    {
        $mover = new MoveJatsAssets(
            '^http://origin-assets/assets/',
            'http://public-assets/path',
            $this->filesystem,
            $this->client
        );

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://origin-assets/assets/figure.jpg"/>
            <graphic xlink:href="http://some-other-host/assets/figure.jpg"/>
            <graphic xlink:href="http://origin-assets/path/assets/figure.jpg"/>
            <graphic xlink:href="http://origin-assets/path/../assets/figure.jpg"/>
            <graphic xlink:href="assets/figure.jpg"/>
            <graphic xlink:href="/assets/figure.jpg"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure.jpg'),
            new Response(200, ['Content-Type' => 'image/jpeg'], 'figure')
        );

        $mover->onManipulate($event);

        $expected = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://public-assets/path/id/v1/cb071d80d1a54f21c8867a038f6a6c66.jpeg"
                mimetype="image" mime-subtype="jpeg"/>
            <graphic xlink:href="http://some-other-host/assets/figure.jpg"/>
            <graphic xlink:href="http://origin-assets/path/assets/figure.jpg"/>
            <graphic xlink:href="http://public-assets/path/id/v1/cb071d80d1a54f21c8867a038f6a6c66.jpeg"
                mimetype="image" mime-subtype="jpeg"/>
            <graphic xlink:href="assets/figure.jpg"/>
            <graphic xlink:href="/assets/figure.jpg"/>
        </body>
    </article>
</item>
XML
        );

        $this->assertXmlStringEqualsXmlString($expected, $task->getDocument());
    }

    /**
     * @test
     */
    public function it_fails_if_the_asset_can_not_be_loaded() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://origin-assets/assets/figure"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure'),
            new Response(404)
        );

        $this->expectException(AssetLoadFailed::class);
        $this->expectExceptionMessage('Failed to load http://origin-assets/assets/figure due to "404 Not Found"');

        $mover->onManipulate($event);
    }

    /**
     * @test
     */
    public function it_fails_if_the_asset_can_not_be_deployed() : void
    {
        $filesystem = $this->createMock(FilesystemInterface::class);
        $filesystem->method('putStream')->willReturn(false);

        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub" xml:base="http://origin-assets/">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="assets/figure1.jpg"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure1.jpg'),
            new Response(200, ['Content-Type' => 'image/jpeg;foo=bar'], 'figure1')
        );

        $this->expectException(AssetDeployFailed::class);
        $this->expectExceptionMessage(
            'Failed to move asset from http://origin-assets/assets/figure1.jpg to '.
            'id/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg'
        );

        $mover->onManipulate($event);
    }

    /**
     * @test
     */
    public function it_fails_if_an_invalid_content_type_is_returned() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://origin-assets/assets/figure"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure'),
            new Response(200, ['Content-Type' => 'foo'], 'figure')
        );

        $this->expectException(InvalidContentType::class);
        $this->expectExceptionMessage('"foo" is an invalid Content-Type');

        $mover->onManipulate($event);
    }

    /**
     * @test
     */
    public function it_uses_the_extension_if_no_content_type_is_returned() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://origin-assets/assets/figure.jpg"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure.jpg'),
            new Response(200, [], 'figure')
        );

        $mover->onManipulate($event);

        $expected = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://public-assets/path/id/v1/cb071d80d1a54f21c8867a038f6a6c66.jpeg"
                mimetype="image" mime-subtype="jpeg"/>
        </body>
    </article>
</item>
XML
        );

        $this->assertXmlStringEqualsXmlString($expected, $task->getDocument());
    }

    /**
     * @test
     */
    public function it_uses_the_extension_if_an_invalid_content_type_is_returned() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://origin-assets/assets/figure.jpg"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure.jpg'),
            new Response(200, ['Content-Type' => 'foo'], 'figure')
        );

        $mover->onManipulate($event);

        $expected = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://public-assets/path/id/v1/cb071d80d1a54f21c8867a038f6a6c66.jpeg"
                mimetype="image" mime-subtype="jpeg"/>
        </body>
    </article>
</item>
XML
        );

        $this->assertXmlStringEqualsXmlString($expected, $task->getDocument());
    }

    /**
     * @test
     */
    public function it_ignores_the_extension_if_it_is_different_to_the_content_type() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://origin-assets/assets/figure.png"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure.png'),
            new Response(200, ['Content-Type' => 'image/jpeg'], 'figure')
        );

        $mover->onManipulate($event);

        $expected = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://public-assets/path/id/v1/cb071d80d1a54f21c8867a038f6a6c66.jpeg"
                mimetype="image" mime-subtype="jpeg"/>
        </body>
    </article>
</item>
XML
        );

        $this->assertXmlStringEqualsXmlString($expected, $task->getDocument());
    }

    /**
     * @test
     */
    public function it_uses_the_extension_if_an_octet_stream_content_type_is_returned() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://origin-assets/assets/figure.jpg"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure.jpg'),
            new Response(200, ['Content-Type' => 'binary/octet-stream'], 'figure')
        );

        $mover->onManipulate($event);

        $expected = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://public-assets/path/id/v1/cb071d80d1a54f21c8867a038f6a6c66.jpeg"
                mimetype="image" mime-subtype="jpeg"/>
        </body>
    </article>
</item>
XML
        );

        $this->assertXmlStringEqualsXmlString($expected, $task->getDocument());
    }

    /**
     * @test
     */
    public function it_fails_if_the_content_type_is_unknown() : void
    {
        $mover = new MoveJatsAssets('.+', 'http://public-assets/path', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://origin-assets/assets/figure.foo"/>
        </body>
    </article>
</item>
XML
        );

        $marking = new Marking();
        $task = new PutTask('service', ItemId::fromString('id'), ItemVersionNumber::fromInt(1), $document);
        $transition = new Transition('transition', 'place1', 'place2');

        $event = new Event($task, $marking, $transition);

        $this->mock->save(
            new Request('GET', 'http://origin-assets/assets/figure.foo'),
            new Response(200, [], 'figure')
        );

        $this->expectException(UnknownContentType::class);
        $this->expectExceptionMessage('Unknown Content-Type');

        $mover->onManipulate($event);
    }
}
