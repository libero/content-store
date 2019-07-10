<?php

declare(strict_types=1);

namespace Libero\JatsContentWorkflow;

use FluentDOM\DOM\Element;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use Libero\MediaType\Exception\InvalidMediaType;
use Libero\MediaType\MediaType;
use Psr\Http\Message\UriInterface;
use function addcslashes;
use function GuzzleHttp\Psr7\mimetype_from_filename;
use function GuzzleHttp\Psr7\uri_for;
use function is_string;

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
function guess_media_type(UriInterface $uri) : MediaType
{
    $guessed = mimetype_from_filename((string) $uri);

    if (!is_string($guessed)) {
        throw new InvalidMediaType('Unable to guess a type');
    }

    return MediaType::fromString($guessed);
}

/**
 * @internal
 */
function delimit_regex(string $regex) : string
{
    return '/'.addcslashes($regex, '/').'/';
}
