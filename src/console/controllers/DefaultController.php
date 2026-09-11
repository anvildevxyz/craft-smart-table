<?php

namespace anvildevxyz\smarttable\console\controllers;

use anvildevxyz\smarttable\fields\SmartTableField;
use Craft;
use craft\console\Controller;
use craft\helpers\Json;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;

/**
 * The bulk/migration half of import-export — a CP "Export CSV/JSON" link is
 * a fine way to pull what's already in one field on one element, but
 * populating a field from an external data source is an ops/developer task,
 * not something worth building a fragile client-side row-rebuilding CP UI
 * for. `craft smart-table/default/import <elementId> <fieldHandle> <file>`
 * (named `DefaultController`, matching the web controller's own naming —
 * a console controller named after the plugin handle itself doubles up in
 * its route: `smart-table/smart-table/import`, not the shorter
 * `smart-table/import` it looks like it should be) reuses the exact same
 * rowsFromCsv()/rowsFromImport() pipeline the CP export link's output
 * round-trips through (see the Integration suite's
 * testExportImportRoundTripsThroughARealElementSave()), so a file exported
 * from the CP is already valid import input.
 */
class DefaultController extends Controller
{
    /**
     * @var string|null 'csv' or 'json' — auto-detected from the file's
     * extension when omitted.
     */
    public ?string $format = null;

    /**
     * @param string $actionID
     * @return array<int, string>
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['format']);
    }

    /**
     * Imports rows into a Smart Table field from a CSV or JSON file,
     * replacing its current value entirely, then saves the element.
     */
    public function actionImport(int $elementId, string $fieldHandle, string $file): int
    {
        if (!is_file($file)) {
            $this->stderr("File not found: $file\n");
            return ExitCode::DATAERR;
        }

        /** @var \craft\base\ElementInterface|null $element */
        $element = Craft::$app->getElements()->getElementById($elementId); // @phpstan-ignore-line
        if ($element === null) {
            $this->stderr("Element $elementId not found.\n");
            return ExitCode::DATAERR;
        }

        $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        if (!$field instanceof SmartTableField) {
            $this->stderr("\"$fieldHandle\" is not a Smart Table field.\n");
            return ExitCode::DATAERR;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            $this->stderr("Couldn't read $file.\n");
            return ExitCode::IOERR;
        }

        $format = $this->format ?? (str_ends_with(strtolower($file), '.json') ? 'json' : 'csv');
        if ($format === 'json') {
            $decoded = Json::decodeIfJson($contents);
            if (!is_array($decoded)) {
                $this->stderr("Couldn't parse $file as JSON.\n");
                return ExitCode::DATAERR;
            }
            $exportedRows = $decoded;
        } else {
            try {
                $exportedRows = $field->rowsFromCsv($contents);
            } catch (InvalidArgumentException | InvalidConfigException $e) {
                $this->stderr("Couldn't parse $file as CSV: {$e->getMessage()}\n");
                return ExitCode::DATAERR;
            }
        }

        $element->setFieldValue($fieldHandle, $field->rowsFromImport($exportedRows));

        if (!Craft::$app->getElements()->saveElement($element)) {
            $this->stderr('Save failed: ' . implode(' ', $element->getErrorSummary(true)) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Imported ' . count($exportedRows) . " row(s) into \"$fieldHandle\" on element $elementId.\n");
        return ExitCode::OK;
    }
}
