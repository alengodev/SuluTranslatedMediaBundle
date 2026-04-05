<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle;

use Alengo\SuluTranslatedMediaBundle\DependencyInjection\Compiler\TranslatedFormatManagerPass;
use Alengo\SuluTranslatedMediaBundle\DependencyInjection\TranslatedMediaExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class TranslatedMediaBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new TranslatedMediaExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TranslatedFormatManagerPass());
    }
}
