<?php

declare(strict_types=1);

namespace Libero\JatsContentWorkflowBundle\Exception;

use Psr\Http\Message\UriInterface;
use Throwable;
use UnexpectedValueException;

class InvalidContentType extends UnexpectedValueException
{
    private $contentType;
    private $uri;

    public function __construct(string $contentType, UriInterface $uri, ?Throwable $previous = null)
    {
        parent::__construct("\"{$contentType}\" is an invalid Content-Type", 0, $previous);

        $this->contentType = $contentType;
        $this->uri = $uri;
    }

    final public function getContentType() : string
    {
        return $this->contentType;
    }

    final public function getUri() : UriInterface
    {
        return $this->uri;
    }
}
