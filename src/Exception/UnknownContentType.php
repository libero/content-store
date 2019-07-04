<?php

declare(strict_types=1);

namespace Libero\ContentStore\Exception;

use Psr\Http\Message\UriInterface;
use Throwable;
use UnexpectedValueException;

class UnknownContentType extends UnexpectedValueException
{
    private $uri;

    public function __construct(UriInterface $uri, ?Throwable $previous = null)
    {
        parent::__construct("Unknown Content-Type for {$uri}", 0, $previous);

        $this->uri = $uri;
    }

    final public function getUri() : UriInterface
    {
        return $this->uri;
    }
}
