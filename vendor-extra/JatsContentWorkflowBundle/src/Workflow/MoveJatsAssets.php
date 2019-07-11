<?php

declare(strict_types=1);

namespace Libero\JatsContentWorkflowBundle\Workflow;

use FluentDOM\DOM\Document;
use FluentDOM\DOM\Element;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Uri;
use League\Flysystem\AdapterInterface;
use League\Flysystem\FilesystemInterface;
use Libero\ContentApiBundle\Model\PutTask;
use Libero\JatsContentWorkflowBundle\Exception\AssetDeployFailed;
use Libero\JatsContentWorkflowBundle\Exception\AssetLoadFailed;
use Libero\JatsContentWorkflowBundle\Exception\InvalidContentType;
use Libero\JatsContentWorkflowBundle\Exception\UnknownContentType;
use Libero\MediaType\Exception\InvalidMediaType;
use Libero\MediaType\MediaType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Symfony\Component\Workflow\Event\Event;
use function GuzzleHttp\Promise\each_limit_all;
use function GuzzleHttp\Psr7\mimetype_from_filename;
use function GuzzleHttp\Psr7\uri_for;
use function in_array;
use function Libero\ContentApiBundle\stream_hash;
use function Libero\JatsContentWorkflowBundle\delimit_regex;
use function Libero\JatsContentWorkflowBundle\element_uri;
use function preg_match;
use function sprintf;

final class MoveJatsAssets
{
    private const IGNORE_CONTENT_TYPES = [
        'application/octet-stream',
        'binary/octet-stream',
    ];
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
                $uri = element_uri($asset);

                try {
                    /** @var ResponseInterface $response */
                    $response = yield $this->client->requestAsync('GET', $uri);
                } catch (GuzzleException $e) {
                    throw AssetLoadFailed::fromException($uri, $e);
                }

                $contentType = $this->contentTypeFor($uri, $response);
                /** @var resource $stream */
                $stream = $response->getBody()->detach();
                $path = $this->pathFor($task, $contentType, $stream);

                $this->updateElement($asset, $contentType, $path);
                $this->deployAsset($contentType, $stream, $path, $uri);
            }
        );
    }

    /**
     * @param resource $stream
     */
    private function deployAsset(MediaType $contentType, $stream, UriInterface $path, UriInterface $origin) : void
    {
        $result = $this->filesystem->putStream(
            $path,
            $stream,
            [
                'mimetype' => (string) $contentType,
                'visibility' => AdapterInterface::VISIBILITY_PUBLIC,
            ]
        );

        if (true === $result) {
            return;
        }

        throw new AssetDeployFailed($origin, $path);
    }

    private function updateElement(Element $element, MediaType $contentType, UriInterface $path) : void
    {
        $element->setAttribute('xlink:href', sprintf('%s/%s', $this->publicUri, $path));

        if (in_array($element->localName, self::HAS_MIMETYPE_ATTRIBUTE, true)) {
            $element->setAttribute('mimetype', $contentType->getType());
            $element->setAttribute('mime-subtype', $contentType->getSubType());
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
    private function pathFor(PutTask $task, MediaType $contentType, $stream) : UriInterface
    {
        $hash = stream_hash($stream);

        $path = sprintf('%s/v%s/%s', $task->getItemId(), $task->getItemVersion(), $hash);

        $extension = ExtensionGuesser::getInstance()->guess($contentType->getEssence());
        if ($extension) {
            $path .= ".{$extension}";
        }

        return uri_for($path);
    }

    private function contentTypeFor(UriInterface $uri, ResponseInterface $response) : MediaType
    {
        $rawType = $response->getHeaderLine('Content-Type');

        try {
            $contentType = MediaType::fromString($response->getHeaderLine('Content-Type'));

            if (in_array($contentType->getEssence(), self::IGNORE_CONTENT_TYPES, true)) {
                throw new InvalidMediaType('Ignored type');
            }
        } catch (InvalidMediaType $e) {
            try {
                $rawType = mimetype_from_filename((string) $uri) ?? $response->getHeaderLine('Content-Type');
                $contentType = MediaType::fromString($rawType);
            } catch (InvalidMediaType $e) {
                throw $rawType ? new InvalidContentType($rawType, $uri, $e) : new UnknownContentType($uri, $e);
            }
        }

        return $contentType;
    }
}
