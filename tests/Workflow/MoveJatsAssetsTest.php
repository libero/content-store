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

        $expected = FluentDOM::load(
            <<<XML
<item xmlns="http://libero.pub" xml:base="http://www.example.com/">
    <article xmlns="http://jats.nlm.nih.gov" xmlns:xlink="http://www.w3.org/1999/xlink">
        <body>
            <graphic xlink:href="http://assets/path/{$path}" mimetype="image" sub-mimetype="jpeg"/>
        </body>
    </article>
</item>
XML
        );

        $this->assertXmlStringEqualsXmlString($expected, $task->getDocument());
        $this->assertTrue($this->filesystem->has($path));
        $this->assertSame(AdapterInterface::VISIBILITY_PUBLIC, $this->filesystem->getVisibility($path));
        $this->assertSame('figure1', $this->filesystem->read($path));
    }
}
