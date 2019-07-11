<?php

declare(strict_types=1);

namespace Libero\JatsContentWorkflowBundle\Exception;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\UriInterface;
use Throwable;
use UnexpectedValueException;

class AssetLoadFailed extends UnexpectedValueException
{
    private $asset;
    private $reason;

    public function __construct(UriInterface $asset, string $reason, ?Throwable $previous = null, int $code = 0)
    {
        parent::__construct("Failed to load {$asset} due to \"{$reason}\"", $code, $previous);

        $this->asset = $asset;
        $this->reason = $reason;
    }

    final public static function fromException(UriInterface $asset, Throwable $previous) : AssetLoadFailed
    {
        if ($previous instanceof RequestException && $response = $previous->getResponse()) {
            $reason = "{$response->getStatusCode()} {$response->getReasonPhrase()}";
        }

        return new AssetLoadFailed($asset, $reason ?? $previous->getMessage(), $previous);
    }

    final public function getAsset() : UriInterface
    {
        return $this->asset;
    }

    final public function getReason() : string
    {
        return $this->reason;
    }
}
