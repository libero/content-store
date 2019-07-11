<?php

declare(strict_types=1);

namespace Libero\JatsContentWorkflowBundle;

use FluentDOM\DOM\Element;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\UriInterface;
use function addcslashes;
use function GuzzleHttp\Psr7\uri_for;

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
function delimit_regex(string $regex) : string
{
    return '/'.addcslashes($regex, '/').'/';
}
