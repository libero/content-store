<?php

declare(strict_types=1);

namespace tests\Libero\ContentStore\Workflow;

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
use Libero\ContentStore\Flysystem\HttpUrlPlugin;
use Libero\ContentStore\Workflow\MoveJatsAssets;
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
    /** @var FilesystemInterface */
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
        $this->filesystem->addPlugin(new HttpUrlPlugin('http://assets/path/'));
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
        $mover = new MoveJatsAssets('~^http://example.com/assets/~', $this->filesystem, $this->client);

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
        $mover = new MoveJatsAssets('~^http://www.example.com/assets/~', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub" xml:base="http://www.example.com/">
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
                        <graphic xlink:href="http://www.example.com/assets/figure2.jpg"/>
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
            new Request('GET', 'http://www.example.com/assets/article.pdf'),
            new Response(200, ['Content-Type' => 'application/pdf'], 'article pdf')
        );

        $this->mock->save(
            new Request('GET', 'http://www.example.com/assets/figure1.jpg'),
            new Response(200, ['Content-Type' => 'image/jpeg;foo=bar'], 'figure1')
        );

        $this->mock->save(
            new Request('GET', 'http://www.example.com/assets/figure2.jpg'),
            new Response(200, ['Content-Type' => 'image/jpeg;foo=bar'], 'figure2 jpeg')
        );

        $this->mock->save(
            new Request('GET', 'http://www.example.com/assets/figure2'),
            new Response(200, ['Content-Type' => 'video/avi'], 'figure2 avi')
        );

        $this->mock->save(
            new Request('GET', 'http://www.example.com/assets/something/figure2.txt'),
            new Response(200, ['Content-Type' => 'application/xml'], 'figure2 xml')
        );

        $this->mock->save(
            new Request('GET', 'http://www.example.com/assets/figure2.pdf'),
            new Response(200, ['Content-Type' => 'application/pdf'], 'figure2 pdf')
        );

        $mover->onManipulate($event);

        $expected = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub" xml:base="http://www.example.com/">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <front>
            <article-meta>
                <self-uri xlink:href="http://assets/path/id/v1/dcd99c5055598bed7350ec58a4153d5d.pdf"/>
            </article-meta>
        </front>
        <body>
            <graphic xlink:href="http://assets/path/id/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg"
                mimetype="image" mime-subtype="jpeg"/>
            <sec>
                <fig>
                    <graphic xlink:href="http://assets/path/id/v1/249c9efda2fc00821bb65c8ca1c89635.jpeg"
                        mimetype="image" mime-subtype="jpeg"/>
                    <media xlink:href="http://assets/path/id/v1/f1aa6a59b56406414301af35cf1a1178"
                        mimetype="video" mime-subtype="avi">
                        <caption>
                            <p>
                                <fig-group>
                                    <alternatives>
                                        <supplementary-material mimetype="application" mime-subtype="xml" 
                                            xlink:href="http://assets/path/id/v1/3f67ade33288e5f9a9f54b8bac3f3042.xml"/>
                                    </alternatives>
                                </fig-group>
                            </p>
                        </caption>
                    </media>
                    <graphic xlink:href="http://assets/path/id/v1/601e38e045a4d1b50350ecf57e4e8630.pdf"
                        mimetype="application" mime-subtype="pdf"/>
                    <alternatives>
                        <graphic xlink:href="http://assets/path/id/v1/249c9efda2fc00821bb65c8ca1c89635.jpeg"
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
            function (array $item) : bool {
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
        $mover = new MoveJatsAssets('~^http://www.example.com/assets/~', $this->filesystem, $this->client);

        $document = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub" xml:base="http://www.example.com/">
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
            new Request('GET', 'http://www.example.com/assets/figure1.jpg'),
            new Response(200, ['Content-Type' => 'image/jpeg;foo=bar'], 'figure1')
        );

        $mover->onManipulate($event);

        $path = 'id/v1/879f77a11b0649cb8af511fa5d6e4a7e.jpeg';

        $this->assertSame('figure1', $this->filesystem->read($path));
        $this->assertSame(AdapterInterface::VISIBILITY_PUBLIC, $this->filesystem->getVisibility($path));
        $this->assertSame('image/jpeg', $this->filesystem->getMimetype($path));
    }
}
