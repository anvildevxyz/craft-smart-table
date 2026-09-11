<?php

namespace anvildevxyz\smarttable\controllers;

use anvildevxyz\smarttable\fields\SmartTableField;
use Craft;
use craft\helpers\Cp;
use craft\helpers\Json;
use craft\web\Controller;
use craft\web\UploadedFile;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    /**
     * A generous ceiling for actionImport()'s uploaded file — an authored
     * grid's legitimate export is nowhere near this size; this only exists to
     * bound memory use for an oversized/malformed upload before it's read
     * fully into memory.
     */
    private const MAX_IMPORT_FILE_SIZE = 10 * 1024 * 1024;

    /**
     * Returns an element-select widget for a relations cell added client-side
     * — either blank, for the grid's "Add a row", or pre-populated with the
     * given `elementIds`, for "Duplicate row" — since both clone a plain
     * cell with no server round-trip and can't render Craft's widget
     * themselves.
     *
     */
    public function actionRelationsCell(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $inputId = $this->requireStringQueryParam('inputId');
        $fieldHandle = $this->requireStringQueryParam('fieldHandle');
        $rowId = $this->requireStringQueryParam('rowId');
        $colId = $this->requireStringQueryParam('colId');
        $typeKey = $this->requireStringQueryParam('typeKey');
        $elementIds = array_values(array_filter(
            array_map(
                static fn(mixed $id): int => is_scalar($id) ? (int)$id : 0,
                (array)$this->request->getQueryParam('elementIds', []),
            ),
            static fn(int $id): bool => $id > 0,
        ));

        $elementClass = SmartTableField::relationElementClass($typeKey);
        if ($elementClass === null) {
            throw new BadRequestHttpException('Invalid relations column type.');
        }

        $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        $column = $field instanceof SmartTableField ? ($field->columns[$colId] ?? []) : [];
        $restriction = SmartTableField::relationPickerRestriction($elementClass, $column);
        $elements = SmartTableField::resolveRestrictedElements($elementClass, $elementIds, $restriction['criteria']);

        $view = $this->getView();

        return $this->asJson([
            'fieldsHtml' => Cp::elementSelectHtml(
                SmartTableField::elementSelectConfig($inputId, $rowId, $colId, $fieldHandle, $elementClass, $elements, $restriction['criteria'], $restriction['sources'])
            ),
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }

    /**
     * Downloads an element's Smart Table field value as CSV or JSON — the
     * CP-side half of import/export (the other half, bulk-populating a
     * field from a file, is the `smart-table/import` console command; a
     * one-off "look at what's in this field" pull and a bulk migration
     * load are different enough use cases that they don't share a UI).
     *
     * requireCpRequest() + the base Controller's default (login required
     * unless a controller opts out via $allowAnonymous, which this one
     * doesn't) keep this CP-only and authenticated; canView() is the
     * actual per-element check — without it, a logged-in user with CP
     * access but no permission on this specific element could still pull
     * its field data just by knowing the element id.
     */
    public function actionExport(): Response
    {
        $this->requireCpRequest();

        $elementId = (int)$this->requireStringQueryParam('elementId');
        $fieldHandle = $this->requireStringQueryParam('fieldHandle');
        $format = $this->request->getQueryParam('format', 'csv');

        /** @var \craft\base\ElementInterface|null $element */
        $element = Craft::$app->getElements()->getElementById($elementId); // @phpstan-ignore-line
        if ($element === null) {
            throw new NotFoundHttpException('Element not found.');
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser === null || !$element->canView($currentUser)) {
            throw new ForbiddenHttpException('You don\'t have permission to view this element.');
        }

        $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        if (!$field instanceof SmartTableField) {
            throw new BadRequestHttpException('Invalid field handle.');
        }

        $rows = $element->getFieldValue($fieldHandle);
        $exported = $field->rowsForExport(is_array($rows) ? $rows : []);

        $filename = "{$fieldHandle}-{$elementId}";
        if ($format === 'json') {
            $content = Json::encode($exported, JSON_PRETTY_PRINT);
            $contentType = 'application/json';
            $filename .= '.json';
        } else {
            $content = $field->rowsToCsv($exported);
            $contentType = 'text/csv';
            $filename .= '.csv';
        }

        $this->response->format = Response::FORMAT_RAW;
        $this->response->content = $content;
        $this->response->headers->set('Content-Type', $contentType);
        $this->response->headers->set('Content-Disposition', "attachment; filename=\"$filename\"");
        return $this->response;
    }

    /**
     * The CP counterpart to the `smart-table/default/import` console
     * command — reuses the exact same rowsFromCsv()/rowsFromImport()
     * parsing pipeline (plus SmartTableField::assertImportColumnsMatch(),
     * a CP-only guard the console command deliberately doesn't have; see
     * that method's own docblock) so a file this same field's own Export
     * CSV/JSON link produced is already valid input here too.
     *
     */
    public function actionImport(): Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $elementId = (int)$this->requireStringBodyParam('elementId');
        $fieldHandle = $this->requireStringBodyParam('fieldHandle');

        /** @var \craft\base\ElementInterface|null $element */
        $element = Craft::$app->getElements()->getElementById($elementId); // @phpstan-ignore-line
        if ($element === null) {
            throw new NotFoundHttpException('Element not found.');
        }
        $currentUser = Craft::$app->getUser()->getIdentity();
        if ($currentUser === null || !$element->canSave($currentUser)) {
            throw new ForbiddenHttpException('You don\'t have permission to edit this element.');
        }

        $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);
        if (!$field instanceof SmartTableField) {
            throw new BadRequestHttpException('Invalid field handle.');
        }

        $uploadedFile = UploadedFile::getInstanceByName('file');
        if ($uploadedFile === null || $uploadedFile->error !== UPLOAD_ERR_OK) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('smart-table', 'Choose a file to import.'),
            ]);
        }

        if ($uploadedFile->size > self::MAX_IMPORT_FILE_SIZE) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('smart-table', 'That file is too large to import.'),
            ]);
        }

        $contents = file_get_contents($uploadedFile->tempName);
        if ($contents === false) {
            return $this->asJson([
                'success' => false,
                'error' => Craft::t('smart-table', 'Couldn’t read the uploaded file.'),
            ]);
        }

        try {
            if (str_ends_with(strtolower($uploadedFile->name), '.json')) {
                $decoded = Json::decodeIfJson($contents);
                if (!is_array($decoded)) {
                    return $this->asJson([
                        'success' => false,
                        'error' => Craft::t('smart-table', 'Couldn’t parse the file as JSON.'),
                    ]);
                }
                $exportedRows = $decoded;
            } else {
                $exportedRows = $field->rowsFromCsv($contents);
            }
            $field->assertImportColumnsMatch($exportedRows);
        } catch (InvalidArgumentException | InvalidConfigException $e) {
            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }

        $element->setFieldValue($fieldHandle, $field->rowsFromImport($exportedRows));

        if (!Craft::$app->getElements()->saveElement($element)) {
            return $this->asJson([
                'success' => false,
                'error' => implode(' ', $element->getErrorSummary(true)) ?: Craft::t('smart-table', 'Couldn’t save the element.'),
            ]);
        }

        return $this->asJson(['success' => true, 'rowCount' => count($exportedRows)]);
    }

    /**
     * The POST counterpart to requireStringQueryParam() — same reasoning:
     * body params are untyped (mixed) at the request layer, so this
     * actually validates rather than blindly casting.
     */
    private function requireStringBodyParam(string $name): string
    {
        $value = $this->request->getRequiredBodyParam($name);
        if (!is_string($value)) {
            throw new BadRequestHttpException("Invalid \"$name\" parameter.");
        }
        return $value;
    }

    /**
     * Query params are untyped (mixed) at the request layer — this actually
     * validates rather than blindly casting, so a non-string param (or a
     * missing one, already handled by getRequiredQueryParam()) is a clean
     * 400 instead of silently becoming the string "Array" or similar.
     */
    private function requireStringQueryParam(string $name): string
    {
        $value = $this->request->getRequiredQueryParam($name);
        if (!is_string($value)) {
            throw new BadRequestHttpException("Invalid \"$name\" parameter.");
        }
        return $value;
    }
}
