<?php

declare(strict_types=1);

namespace tests\Libero\ContentStore;

use function fopen;
use function fwrite;
use function rewind;

/**
 * @return resource
 */
function stream_from_string(string $string)
{
    /** @var resource $stream */
    $stream = fopen('php://memory', 'rb+');
    fwrite($stream, $string);
    rewind($stream);

    return $stream;
}
