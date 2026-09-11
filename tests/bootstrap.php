<?php

declare(strict_types=1);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// If vendor/ is a symlink (e.g. a git worktree sharing it), Composer's
// PSR-4 map can point this namespace at the wrong checkout's src/.
// This override keeps every other vendor mapping intact, and always
// uses this checkout's own src/ for its own classes.
$loader->setPsr4('anvildevxyz\\smarttable\\', [dirname(__DIR__) . '/src']);

// Loads the \Craft class definition only — no app boot, no DB. Craft::t()
// (and anything else static that checks Craft::$app first) degrades to its
// plain-PHP fallback when $app is null, which is enough for the settings/
// validation logic under test here.
require dirname(__DIR__) . '/vendor/craftcms/cms/lib/yii2/Yii.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';
