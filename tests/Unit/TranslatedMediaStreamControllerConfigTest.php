<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Tests\Unit;

use Alengo\SuluTranslatedMediaBundle\Controller\TranslatedMediaStreamController;
use Alengo\SuluTranslatedMediaBundle\DependencyInjection\Compiler\TranslatedMediaStreamControllerPass;
use Alengo\SuluTranslatedMediaBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * `master_file_download.stream_remote` config node and how TranslatedMediaStreamControllerPass applies it
 * to Sulu's `sulu_media.media_stream_controller` service.
 */
final class TranslatedMediaStreamControllerConfigTest extends TestCase
{
    private const SERVICE = 'sulu_media.media_stream_controller';
    private const PARAMETER = 'alengo_translated_media.master_file_download.stream_remote';

    public function testStreamRemoteDefaultsToAdmin(): void
    {
        $config = $this->processConfig([]);

        self::assertSame(TranslatedMediaStreamController::STREAM_REMOTE_ADMIN, $config['master_file_download']['stream_remote']);
    }

    public function testStreamRemoteRejectsUnknownValue(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processConfig(['master_file_download' => ['stream_remote' => 'sometimes']]);
    }

    public function testPassReplacesControllerAndInjectsModeAndRequestStack(): void
    {
        $container = $this->containerWithSuluController(TranslatedMediaStreamController::STREAM_REMOTE_ADMIN);

        (new TranslatedMediaStreamControllerPass())->process($container);

        $definition = $container->getDefinition(self::SERVICE);
        self::assertSame(TranslatedMediaStreamController::class, $definition->getClass());
        // Constructor arguments of Sulu's definition stay untouched.
        self::assertSame(['sulu-arg'], $definition->getArguments());

        $calls = $definition->getMethodCalls();
        self::assertSame('setStreamRemote', $calls[0][0]);
        self::assertSame([TranslatedMediaStreamController::STREAM_REMOTE_ADMIN], $calls[0][1]);
        self::assertSame('setRequestStack', $calls[1][0]);
        self::assertInstanceOf(Reference::class, $calls[1][1][0]);
        self::assertSame('request_stack', (string) $calls[1][1][0]);
    }

    public function testPassLeavesSuluControllerUntouchedForModeNone(): void
    {
        $container = $this->containerWithSuluController(TranslatedMediaStreamController::STREAM_REMOTE_NONE);

        (new TranslatedMediaStreamControllerPass())->process($container);

        $definition = $container->getDefinition(self::SERVICE);
        self::assertSame('Sulu\Bundle\MediaBundle\Controller\MediaStreamController', $definition->getClass());
        self::assertSame([], $definition->getMethodCalls());
    }

    public function testPassIsNoOpWithoutSuluController(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(self::PARAMETER, TranslatedMediaStreamController::STREAM_REMOTE_ADMIN);

        (new TranslatedMediaStreamControllerPass())->process($container);

        self::assertFalse($container->hasDefinition(self::SERVICE));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function processConfig(array $config): array
    {
        /** @var array<string, mixed> $processed */
        $processed = (new Processor())->processConfiguration(new Configuration(), [$config]);

        return $processed;
    }

    private function containerWithSuluController(string $streamRemote): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(self::PARAMETER, $streamRemote);
        $container->setDefinition(
            self::SERVICE,
            new Definition('Sulu\Bundle\MediaBundle\Controller\MediaStreamController', ['sulu-arg']),
        );

        return $container;
    }
}
