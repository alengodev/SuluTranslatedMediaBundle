<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Controller;

/**
 * Static-analysis shim for Sulu's path-cleanup service.
 *
 * Sulu moved this service's interface between versions
 * (Sulu\Component\PHPCR\PathCleanupInterface -> Sulu\Route\…\PathCleanup\PathCleanupInterface)
 * and the MediaStreamController property that holds it was renamed (pathCleaner -> pathCleanup).
 * TranslatedMediaStreamController reads whichever property exists and treats it as this
 * contract purely for type-checking; at runtime the real Sulu service is used as-is.
 */
interface PathCleanupContract
{
    public function cleanup(string $dirty, string $locale): string;
}
