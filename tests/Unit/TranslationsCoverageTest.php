<?php

declare(strict_types=1);

namespace anvildevxyz\smarttable\tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every translatable string in the source must exist in every shipped locale
 * file, and no locale value may be empty. A new Craft::t()/|t() string fails
 * this test until translated — translations can never silently drift.
 */
class TranslationsCoverageTest extends TestCase
{
    public function testAllLocalesCoverAllSourceStrings(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $strings = $this->collectSourceStrings($src);
        self::assertNotEmpty($strings, 'source string extraction must find strings');
        self::assertContains('Smart Table', $strings);
        self::assertContains('Yes', $strings);

        foreach (['de', 'fr', 'es', 'it'] as $locale) {
            $file = "$src/translations/$locale/smart-table.php";
            self::assertFileExists($file);

            /** @var array<string, string> $map */
            $map = require $file;

            $missing = array_values(array_diff($strings, array_keys($map)));
            self::assertSame([], $missing, "Locale '$locale' is missing translations");

            foreach ($map as $key => $value) {
                self::assertNotSame('', trim($value), "Locale '$locale' has an empty translation for '$key'");
            }
        }
    }

    /**
     * Extracts every `Craft::t('smart-table', …)` and `|t('smart-table')`
     * source string from PHP and Twig files.
     *
     * @return string[]
     */
    private function collectSourceStrings(string $src): array
    {
        $strings = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $ext = $file->getExtension();
            if (!in_array($ext, ['php', 'twig'], true)) {
                continue;
            }
            $text = (string)file_get_contents($file->getPathname());

            if ($ext === 'php') {
                preg_match_all("/Craft::t\(\s*'smart-table',\s*'((?:[^'\\\\]|\\\\.)*)'/", $text, $matches);
            } else {
                preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'\|t\('smart-table'\s*[,)]/", $text, $matches);
            }
            foreach ($matches[1] as $match) {
                $strings[] = str_replace("\\'", "'", $match);
            }
        }

        return array_values(array_unique($strings));
    }
}
