<?php

declare(strict_types=1);

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // Generated/runtime artifacts under tests/ — not code this project owns
    // the formatting of (compiled Twig caches, Codeception's generated actor
    // trait), gitignored, and absent on a fresh checkout until the
    // integration suite has actually run once.
    $ecsConfig->skip([
        __DIR__ . '/tests/_craft/storage/*',
        __DIR__ . '/tests/_support/_generated/*',
    ]);

    $ecsConfig->sets([
        SetList::CRAFT_CMS_4,
    ]);
};
