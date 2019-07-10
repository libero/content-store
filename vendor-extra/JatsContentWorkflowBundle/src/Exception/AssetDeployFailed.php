<?php

declare(strict_types=1);

namespace Libero\JatsContentWorkflowBundle\Exception;

use Psr\Http\Message\UriInterface;
use Throwable;
use UnexpectedValueException;

class AssetDeployFailed extends UnexpectedValueException
{
    private $from;
    private $to;

    public function __construct(UriInterface $from, UriInterface $to, ?Throwable $previous = null, int $code = 0)
    {
        parent::__construct("Failed to move asset from {$from} to {$to}", $code, $previous);

        $this->from = $from;
        $this->to = $to;
    }

    final public function getFrom() : UriInterface
    {
        return $this->from;
    }

    final public function getTo() : UriInterface
    {
        return $this->to;
    }
}
