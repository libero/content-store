<?php

declare(strict_types=1);

namespace Libero\ContentStore\Workflow;

use FluentDOM\DOM\Element;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use League\Flysystem\FilesystemInterface;
use Libero\ContentApiBundle\Model\PutTask;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;
use Symfony\Component\Workflow\Event\Event;
use UnexpectedValueException;
use function count;
use function explode;
use function GuzzleHttp\Promise\all;
use function GuzzleHttp\Psr7\uri_for;
use function implode;
use function in_array;
use function is_callable;
use function is_resource;
use function Libero\ContentApiBundle\stream_hash;
use function sprintf;

final class MoveJatsAssets implements EventSubscriberInterface
{
    private $client;
    private $filesystem;

    public function __construct(FilesystemInterface $filesystem, ClientInterface $client)
    {
        $this->filesystem = $filesystem;
        $this->client = $client;

        if (!is_callable([$filesystem, 'getHttpUrl'])) {
            throw new InvalidArgumentException('Requires the HTTP URL plugin');
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
        $assets = $document('(//jats:graphic|//jats:inline-graphic)[@xlink:href]');

        $promises = [];

        foreach ($assets as $asset) {
            $uri = uri_for($asset->getAttribute('xlink:href') ?? '');

            if ($asset->baseURI && !$uri->getScheme()) {
                $uri = UriResolver::resolve(uri_for($asset->baseURI), $uri);
            }

            if (!in_array($uri->getScheme(), ['http', 'https'], true)) {
                throw new UnexpectedValueException('Not an absolute HTTP path');
            }

            $promises[] = $this->client
                ->requestAsync('GET', $uri, ['http_errors' => true])
                ->then(
                    function (ResponseInterface $response) use ($asset, $task) {
                        $contentType = explode(';', $response->getHeaderLine('Content-Type'), 1)[0] ?? '';
                        $contentType = explode('/', $contentType, 2);

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

                        $this->filesystem->putStream($path, $stream);

                        $asset->setAttribute('xlink:href', $this->filesystem->getHttpUrl($path));
                        $asset->setAttribute('mimetype', $contentType[0]);
                        $asset->setAttribute('sub-mimetype', $contentType[1]);
                    }
                );
        }

        all($promises)->wait();
    }
}
