<?php

/**
 * Craft integration-suite bootstrap.
 *
 * Referenced by the ROOT codeception.yml. Boots a real Craft via
 * craft\test\TestSetup against the plugin's OWN vendor/ (CRAFT_VENDOR_PATH
 * below), so the suite is isolated from the surrounding dev project.
 */

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');
date_default_timezone_set('UTC');

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'templates');
define('CRAFT_CONFIG_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . DIRECTORY_SEPARATOR . '_craft' . DIRECTORY_SEPARATOR . 'translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor');
define('CRAFT_ROOT_PATH', dirname(__DIR__));

// The storage tree is runtime-only and gitignored, so it is absent on a fresh
// checkout (CI, clean clone). Craft resolves CRAFT_STORAGE_PATH with realpath(),
// which returns false for a missing directory — the storage base then collapses
// to '' and @runtime becomes "/runtime" (mkdir permission denied). Create the
// tree up front so the suite is portable.
foreach (['', '/runtime', '/logs'] as $subPath) {
    $dir = CRAFT_STORAGE_PATH . $subPath;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

TestSetup::configureCraft();
