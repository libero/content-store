<?php

declare(strict_types=1);

namespace Libero\ContentStore\Workflow;

use FluentDOM\DOM\Document;
use FluentDOM\DOM\Element;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use League\Flysystem\AdapterInterface;
use League\Flysystem\FilesystemInterface;
use Libero\ContentApiBundle\Model\PutTask;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Symfony\Component\Workflow\Event\Event;
use function GuzzleHttp\Promise\each_limit_all;
use function implode;
use function in_array;
use function Libero\ContentApiBundle\stream_hash;
use function Libero\ContentStore\delimit_regex;
use function Libero\ContentStore\element_uri;
use function Libero\ContentStore\parse_media_type;
use function preg_match;
use function sprintf;

final class MoveJatsAssets implements EventSubscriberInterface
{
    private const HAS_MIMETYPE_ATTRIBUTE = [
        'graphic',
        'inline-graphic',
        'inline-media',
        'inline-supplementary-material',
        'media',
        'supplementary-material',
    ];

    private $client;
    private $concurrency;
    private $filesystem;
    private $originWhitelist;
    private $publicUri;

    public function __construct(
        string $originWhitelist,
        string $publicUri,
        FilesystemInterface $filesystem,
        ClientInterface $client,
        int $concurrency = 10
    ) {
        $this->originWhitelist = delimit_regex($originWhitelist);
        $this->publicUri = $publicUri;
        $this->filesystem = $filesystem;
        $this->client = $client;
        $this->concurrency = $concurrency;
    }

    public static function getSubscribedEvents() : array
    {
        return [
            'workflow.libero.content_store.put.transition.manipulate' => 'onManipulate',
        ];
    }

    public function onManipulate(Event $event) : void
    {
        /** @var PutTask $task */
        $task = $event->getSubject();

        each_limit_all(
            $this->processAssets($this->findLinkedElements($task->getDocument()), $task),
            $this->concurrency
        )->wait();
    }

    /**
     * @return iterable<Element>
     */
    private function findLinkedElements(Document $document) : iterable
    {
        $document->registerNamespace('jats', 'http://jats.nlm.nih.gov');
        $document->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

        yield from $document('//jats:article//jats:*[@xlink:href]');
    }

    private function processAssets(iterable $elements, PutTask $task) : iterable
    {
        foreach ($elements as $element) {
            if ($this->shouldProcess($element)) {
                yield $this->processAsset($element, $task);
            }
        }
    }

    private function processAsset(Element $asset, PutTask $task) : PromiseInterface
    {
        return new Coroutine(
            function () use ($asset, $task) : iterable {
                /** @var ResponseInterface $response */
                $response = yield $this->client->requestAsync('GET', element_uri($asset));

                $contentType = parse_media_type($response->getHeaderLine('Content-Type'));
                /** @var resource $stream */
                $stream = $response->getBody()->detach();
                $path = $this->pathFor($task, $contentType, $stream);

                $this->updateElement($asset, $contentType, $path);
                $this->deployAsset($contentType, $stream, $path);
            }
        );
    }

    /**
     * @param resource $stream
     */
    private function deployAsset(array $contentType, $stream, string $path) : void
    {
        $this->filesystem->putStream(
            $path,
            $stream,
            [
                'mimetype' => implode('/', $contentType),
                'visibility' => AdapterInterface::VISIBILITY_PUBLIC,
            ]
        );
    }

    private function updateElement(Element $element, array $contentType, string $path) : void
    {
        $element->setAttribute('xlink:href', sprintf('%s/%s', $this->publicUri, $path));

        if (in_array($element->localName, self::HAS_MIMETYPE_ATTRIBUTE, true)) {
            $element->setAttribute('mimetype', $contentType[0]);
            $element->setAttribute('mime-subtype', $contentType[1]);
        }
    }

    private function shouldProcess(Element $element) : bool
    {
        $uri = element_uri($element);

        return Uri::isAbsolute($uri) && preg_match($this->originWhitelist, (string) $uri);
    }

    /**
     * @param resource $stream
     */
    private function pathFor(PutTask $task, array $contentType, $stream) : string
    {
        $hash = stream_hash($stream);

        $path = sprintf('%s/v%s/%s', $task->getItemId(), $task->getItemVersion(), $hash);

        $extension = ExtensionGuesser::getInstance()->guess(implode('/', $contentType));
        if ($extension) {
            $path .= ".{$extension}";
        }

        return $path;
    }
}
