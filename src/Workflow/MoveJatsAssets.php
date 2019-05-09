<?php

declare(strict_types=1);

namespace Libero\ContentStore\Workflow;

use FluentDOM\DOM\Document;
use FluentDOM\DOM\Element;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use League\Flysystem\AdapterInterface;
use League\Flysystem\FilesystemInterface;
use Libero\ContentApiBundle\Model\PutTask;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Symfony\Component\Workflow\Event\Event;
use UnexpectedValueException;
use function array_shift;
use function count;
use function GuzzleHttp\Promise\all;
use function GuzzleHttp\Psr7\uri_for;
use function implode;
use function in_array;
use function Libero\ContentApiBundle\stream_hash;
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
    private $filesystem;
    private $origin;
    private $publicUri;

    public function __construct(
        string $origin,
        string $publicUri,
        FilesystemInterface $filesystem,
        ClientInterface $client
    ) {
        $this->origin = $origin;
        $this->publicUri = $publicUri;
        $this->filesystem = $filesystem;
        $this->client = $client;
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

        $promises = [];

        foreach ($this->findAssets($task->getDocument()) as $asset) {
            $uri = $this->elementUri($asset);

            if (!$uri->getScheme() || 0 === preg_match($this->origin, (string) $uri)) {
                continue;
            }

            $promises[] = $this->client
                ->requestAsync('GET', $uri, ['http_errors' => true])
                ->then(
                    function (ResponseInterface $response) use ($asset, $task) {
                        $this->handleResponse($response, $asset, $task);
                    }
                );
        }

        all($promises)->wait();
    }

    /**
     * @return iterable<Element>
     */
    private function findAssets(Document $document) : iterable
    {
        $document->registerNamespace('jats', 'http://jats.nlm.nih.gov');
        $document->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

        /** @var iterable<Element> $assets */
        $assets = $document('//jats:article//jats:*[@xlink:href]');

        return $assets;
    }

    private function elementUri(Element $element) : UriInterface
    {
        $uri = uri_for($element->getAttribute('xlink:href') ?? '');

        if ($element->baseURI && !$uri->getScheme()) {
            $uri = UriResolver::resolve(uri_for($element->baseURI), $uri);
        }

        return UriNormalizer::normalize($uri);
    }

    private function handleResponse(ResponseInterface $response, $asset, $task) : void
    {
        $contentType = $this->parseMediaType($response->getHeaderLine('Content-Type'));
        /** @var resource $stream */
        $stream = $response->getBody()->detach();
        $path = $this->pathFor($task, $contentType, $stream);

        $this->updateElement($asset, $contentType, $path);

        $this->filesystem->putStream(
            $path,
            $stream,
            [
                'mimetype' => implode('/', $contentType),
                'visibility' => AdapterInterface::VISIBILITY_PUBLIC,
            ]
        );
    }

    private function parseMediaType(string $mediaType) : array
    {
        preg_match('~^(.+?)/(.+?)(?:$|;)~', $mediaType, $contentType);
        array_shift($contentType);

        if (2 !== count($contentType)) {
            throw new UnexpectedValueException('Invalid content-type provided');
        }

        return $contentType;
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

    private function updateElement(Element $element, array $contentType, string $path) : void
    {
        $element->setAttribute('xlink:href', sprintf('%s/%s', $this->publicUri, $path));

        if (in_array($element->localName, self::HAS_MIMETYPE_ATTRIBUTE, true)) {
            $element->setAttribute('mimetype', $contentType[0]);
            $element->setAttribute('mime-subtype', $contentType[1]);
        }
    }
}
