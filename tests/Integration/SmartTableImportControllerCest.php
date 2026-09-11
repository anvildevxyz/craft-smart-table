<?php

namespace anvildevxyz\smarttable\tests\integration;

use anvildevxyz\smarttable\controllers\DefaultController;
use anvildevxyz\smarttable\fields\SmartTableField;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\web\UploadedFile;
use IntegrationTester;

require_once __DIR__ . '/craft-web-is-uploaded-file-stub.php';

/**
 * Exercises DefaultController::actionImport() — the CP-side counterpart to
 * the console `smart-table/default/import` command — end to end against a
 * real element save.
 *
 * Instantiates the web DefaultController directly and calls its own
 * runAction(), rather than routing through Craft::$app->runAction('smart-
 * table/default/import'): Codeception runs as a CLI process, and Yii's
 * default getIsConsoleRequest() is `PHP_SAPI === 'cli'`, so the plugin's
 * own controllerNamespace resolves to the CONSOLE DefaultController for
 * that route in this process — going through the route string would
 * silently invoke the console command's actionImport(), not this one.
 * Calling the class directly sidesteps that ambiguity while still
 * exercising the same real beforeAction()/requireCpRequest()/
 * requirePostRequest() filter chain a live request would.
 *
 * mockCraftMethods('request', ...) stands in for the parts of a real HTTP
 * request this process never sends (a control-panel Origin, a valid CSRF
 * token, an Accept: application/json header) — everything else (body
 * params, the uploaded file) goes through the request component's real,
 * untouched implementation via $_POST/$_FILES, same as a live request.
 */
class SmartTableImportControllerCest
{
    private const HANDLE = 'smartTableImportTest';

    private SmartTableField $field;
    private Entry $entry;
    private User $admin;

    public function _before(IntegrationTester $I): void
    {
        $fixture = $I->createSmartTableSection(self::HANDLE);
        $this->field = $fixture['field'];
        $this->entry = $I->createEntry($fixture, 'Import Test Entry');

        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
        ];
        $this->field->minRows = null;
        $this->field->maxRows = null;
        $this->field->clearErrors();
        $I->fieldOnLayout($this->entry, self::HANDLE)->columns = $this->field->columns;
        // DefaultController resolves the field via Craft::$app->getFields()
        // ->getFieldByHandle() — a third, independently cached instance of
        // the same field, distinct from both $fixture['field'] and the
        // entry layout's own copy (see syncFieldToEntryLayout()'s docblock
        // in SmartTableFieldCest for the full explanation). The controller
        // under test needs this one synced too, or it validates imports
        // against the fixture's default single blank column instead of the
        // columns this test actually configured.
        $fieldsServiceField = Craft::$app->getFields()->getFieldByHandle(self::HANDLE);
        $I->assertInstanceOf(SmartTableField::class, $fieldsServiceField);
        $fieldsServiceField->columns = $this->field->columns;

        $admin = User::find()->admin()->one();
        $I->assertInstanceOf(User::class, $admin, 'a seeded admin user must exist');
        $this->admin = $admin;

        UploadedFile::reset();
    }

    public function _after(): void
    {
        UploadedFile::reset();
        unset($_FILES['file']);
    }

    public function importRoundTripsThroughARealExportedCsvFile(IntegrationTester $I): void
    {
        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Original', 1 => true, '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($this->entry), implode(' ', $this->entry->getErrorSummary(true)));

        $rows = $this->field->normalizeValue($this->entry->getFieldValue(self::HANDLE), $this->entry);
        $csv = $this->field->rowsToCsv($this->field->rowsForExport($rows));

        $response = $this->runImport($I, [
            'elementId' => (string)$this->entry->id,
            'fieldHandle' => self::HANDLE,
        ], $csv, 'export.csv');

        $I->assertTrue($response['success'] ?? false, json_encode($response));

        $reloaded = Craft::$app->getElements()->getElementById($this->entry->id, Entry::class);
        $I->assertInstanceOf(Entry::class, $reloaded);
        $roundTripped = $reloaded->getFieldValue(self::HANDLE);
        $I->assertSame('Original', $roundTripped[0]['label']);
        $I->assertTrue($roundTripped[0]['active']);
    }

    public function importRejectsAMissingColumnHandleWithoutSaving(IntegrationTester $I): void
    {
        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Kept value', 1 => true, '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($this->entry), implode(' ', $this->entry->getErrorSummary(true)));

        // A header this field doesn't recognize at all ("status" isn't a
        // configured column) and missing both real columns entirely —
        // simulates uploading the wrong field's export, or a badly
        // hand-edited file.
        $badCsv = "isHeader,rowClass,status\n,,draft\n";

        $response = $this->runImport($I, [
            'elementId' => (string)$this->entry->id,
            'fieldHandle' => self::HANDLE,
        ], $badCsv, 'bad.csv');

        $I->assertFalse($response['success'] ?? true);
        $I->assertStringContainsString('missing column(s)', $response['error'] ?? '');
        $I->assertStringContainsString('unrecognized column(s): status', $response['error'] ?? '');

        $reloaded = Craft::$app->getElements()->getElementById($this->entry->id, Entry::class);
        $I->assertInstanceOf(Entry::class, $reloaded);
        $untouched = $reloaded->getFieldValue(self::HANDLE);
        $I->assertSame('Kept value', $untouched['row1']['label'], 'a rejected import must not overwrite the saved value');
    }

    public function importRejectsAMalformedCsvWithoutSaving(IntegrationTester $I): void
    {
        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Kept value', 1 => false, '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($this->entry), implode(' ', $this->entry->getErrorSummary(true)));

        // Header declares 3 columns; the data line only has 2 — the same
        // malformed-CSV shape rowsFromCsv() already rejects for the console
        // command, now surfaced as a clean inline error instead of a 500.
        $malformedCsv = "isHeader,rowClass,label,active\n,,Only one value\n";

        $response = $this->runImport($I, [
            'elementId' => (string)$this->entry->id,
            'fieldHandle' => self::HANDLE,
        ], $malformedCsv, 'malformed.csv');

        $I->assertFalse($response['success'] ?? true);
        $I->assertStringContainsString('Malformed CSV', $response['error'] ?? '');

        $reloaded = Craft::$app->getElements()->getElementById($this->entry->id, Entry::class);
        $I->assertInstanceOf(Entry::class, $reloaded);
        $untouched = $reloaded->getFieldValue(self::HANDLE);
        $I->assertSame('Kept value', $untouched['row1']['label'], 'a rejected import must not overwrite the saved value');
    }

    public function importReturns404ForAnUnknownElement(IntegrationTester $I): void
    {
        $this->stageUpload("isHeader,rowClass,label,active\n,,Hello,\n", 'export.csv');
        $_POST = ['elementId' => '999999999', 'fieldHandle' => self::HANDLE];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->mockAuthenticatedCpJsonRequest($I, $this->admin);

        $I->expectThrowable(\yii\web\NotFoundHttpException::class, function() {
            $this->buildController()->runAction('import');
        });
    }

    /**
     * @param array<string, string> $bodyParams
     * @return array<string, mixed>
     */
    private function runImport(IntegrationTester $I, array $bodyParams, string $fileContents, string $fileName): array
    {
        $this->stageUpload($fileContents, $fileName);
        $_POST = $bodyParams;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->mockAuthenticatedCpJsonRequest($I, $this->admin);

        /** @var \yii\web\Response $response */
        $response = $this->buildController()->runAction('import');

        /** @var array<string, mixed> $data */
        $data = $response->data;
        return $data;
    }

    private function buildController(): DefaultController
    {
        return new DefaultController('default', Craft::$app);
    }

    private function mockAuthenticatedCpJsonRequest(IntegrationTester $I, User $identity): void
    {
        $I->mockCraftMethods('request', [
            'getIsCpRequest' => true,
            'getAcceptsJson' => true,
            'validateCsrfToken' => true,
        ]);
        Craft::$app->getUser()->setIdentity($identity);
    }

    private function stageUpload(string $contents, string $fileName): void
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'smarttable-import-test-');
        file_put_contents($tempPath, $contents);

        $_FILES['file'] = [
            'name' => $fileName,
            'type' => 'text/csv',
            'tmp_name' => $tempPath,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ];
        UploadedFile::reset();
    }
}
