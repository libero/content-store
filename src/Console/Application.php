<?php

declare(strict_types=1);

namespace Libero\ContentStore\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application as BaseApplication;
use Symfony\Component\HttpKernel\KernelInterface;

final class Application extends BaseApplication
{
    public function __construct(KernelInterface $kernel)
    {
        parent::__construct($kernel);

        $inputDefinition = $this->getDefinition();
        $options = $inputDefinition->getOptions();
        unset($options['env']);
        unset($options['no-debug']);
        $inputDefinition->setOptions($options);

        $this->setName('Content Store');
        $this->setVersion('master');
    }
}
