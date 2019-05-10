<?php

declare(strict_types=1);

namespace Libero\ContentStore;

use FluentDOM\DOM\Element;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;
use UnexpectedValueException;
use function array_shift;
use function count;
use function GuzzleHttp\Psr7\uri_for;
use function preg_match;

/**
 * @internal
 */
function element_uri(Element $element) : UriInterface
{
    $uri = uri_for($element->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?? '');

    if ($element->baseURI) {
        $uri = UriResolver::resolve(uri_for($element->baseURI), $uri);
    }

    return UriNormalizer::normalize($uri);
}

/**
 * @internal
 */
function parse_media_type(string $mediaType) : array
{
    preg_match('~^(.+?)/(.+?)(?:$|;)~', $mediaType, $contentType);
    array_shift($contentType);

    if (2 !== count($contentType)) {
        throw new UnexpectedValueException('Invalid content-type provided');
    }

    return $contentType;
}
