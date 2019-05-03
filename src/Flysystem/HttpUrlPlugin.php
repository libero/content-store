<?php

declare(strict_types=1);

namespace Libero\ContentStore\Flysystem;

use League\Flysystem\FilesystemInterface;
use League\Flysystem\PluginInterface;
use function ltrim;
use function rtrim;
use function sprintf;

final class HttpUrlPlugin implements PluginInterface
{
    private $baseUrl;
    private $filesystem;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = $baseUrl;
    }

    public function setFilesystem(FilesystemInterface $filesystem) : void
    {
        $this->filesystem = $filesystem;
    }

    public function getMethod() : string
    {
        return 'getHttpUrl';
    }

    public function handle(string $path) : string
    {
        return sprintf('%s/%s', rtrim($this->baseUrl, '/'), ltrim($path, '/'));
    }
}
