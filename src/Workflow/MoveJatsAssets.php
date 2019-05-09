<?php

declare(strict_types=1);

namespace Libero\ContentStore\Workflow;

use FluentDOM\DOM\Element;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use League\Flysystem\AdapterInterface;
use League\Flysystem\FilesystemInterface;
use Libero\ContentApiBundle\Model\PutTask;
use Psr\Http\Message\ResponseInterface;
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
use function is_callable;
use function is_resource;
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

    public function __construct(string $origin, FilesystemInterface $filesystem, ClientInterface $client)
    {
        $this->origin = $origin;
        $this->filesystem = $filesystem;
        $this->client = $client;

        if (!is_callable([$filesystem, 'getPublicUrl'])) {
            throw new InvalidArgumentException('Requires the public URL plugin');
        }
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
        $document = $task->getDocument();

        $document->registerNamespace('jats', 'http://jats.nlm.nih.gov');
        $document->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');

        /** @var array<Element> $assets */
        $assets = $document('//jats:article//jats:*[@xlink:href]');

        $promises = [];

        foreach ($assets as $asset) {
            $uri = uri_for($asset->getAttribute('xlink:href') ?? '');

            if ($asset->baseURI && !$uri->getScheme()) {
                $uri = UriResolver::resolve(uri_for($asset->baseURI), $uri);
            }

            $uri = UriNormalizer::normalize($uri);

            if (!$uri->getScheme() || 0 === preg_match($this->origin, (string) $uri)) {
                continue;
            }

            $promises[] = $this->client
                ->requestAsync('GET', $uri, ['http_errors' => true])
                ->then(
                    function (ResponseInterface $response) use ($asset, $task) {
                        preg_match('~^(.+?)/(.+?)(?:$|;)~', $response->getHeaderLine('Content-Type'), $contentType);
                        array_shift($contentType);

                        if (2 !== count($contentType)) {
                            throw new UnexpectedValueException('Invalid content-type provided');
                        }

                        $stream = $response->getBody()->detach();

                        if (!is_resource($stream)) {
                            throw new UnexpectedValueException('No stream provided');
                        }

                        $hash = stream_hash($stream);

                        $path = sprintf('%s/v%s/%s', $task->getItemId(), $task->getItemVersion(), $hash);

                        $extension = ExtensionGuesser::getInstance()->guess(implode('/', $contentType));
                        if ($extension) {
                            $path .= ".{$extension}";
                        }

                        $this->filesystem->putStream(
                            $path,
                            $stream,
                            [
                                'mimetype' => implode('/', $contentType),
                                'visibility' => AdapterInterface::VISIBILITY_PUBLIC,
                            ]
                        );

                        $asset->setAttribute('xlink:href', $this->filesystem->getPublicUrl($path));

                        if (in_array($asset->localName, self::HAS_MIMETYPE_ATTRIBUTE, true)) {
                            $asset->setAttribute('mimetype', $contentType[0]);
                            $asset->setAttribute('mime-subtype', $contentType[1]);
                        }
                    }
                );
        }

        all($promises)->wait();
    }
}
