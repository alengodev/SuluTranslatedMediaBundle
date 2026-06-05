<?php

declare(strict_types=1);

/*
 * Test autoloader.
 *
 * Uses the bundle's own vendor/autoload.php when present (CI / local `composer install`).
 * Falls back to an external autoloader given via the TEST_AUTOLOAD environment variable
 * (e.g. a host Sulu project's vendor/autoload.php), registering the bundle + test PSR-4
 * roots on top of it so the classes resolve.
 */

(static function (): void {
    $candidates = [__DIR__ . '/../vendor/autoload.php'];

    $external = \getenv('TEST_AUTOLOAD');
    if (\is_string($external) && '' !== $external) {
        $candidates[] = $external;
    }

    foreach ($candidates as $autoload) {
        if (!\is_file($autoload)) {
            continue;
        }

        $loader = require $autoload;
        if (\is_object($loader) && \method_exists($loader, 'addPsr4')) {
            $loader->addPsr4('Alengo\\SuluTranslatedMediaBundle\\', __DIR__ . '/../src/');
            $loader->addPsr4('Alengo\\SuluTranslatedMediaBundle\\Tests\\', __DIR__ . '/');
        }

        return;
    }

    \fwrite(\STDERR, "Could not find an autoloader. Run 'composer install' or set TEST_AUTOLOAD.\n");
    exit(1);
})();
