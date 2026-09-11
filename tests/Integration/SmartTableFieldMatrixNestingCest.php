<?php

namespace anvildevxyz\smarttable\tests\integration;

use Craft;
use craft\db\Query;
use craft\db\Table as DbTable;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\models\EntryType;
use DateTime;
use IntegrationTester;

/**
 * Exercises SmartTableField nested inside a Matrix field on a real entry —
 * the "nested use inside Matrix" gap the README's Status section used to
 * flag as untested. In Craft 5, a Matrix "block" is itself a real Entry
 * element with its own EntryType and FieldLayout, so a nested SmartTableField
 * resolves and saves through exactly the same normalizeValue()/
 * serializeValue()/afterElementSave() path a top-level one does — the only
 * open question this suite actually answers is whether that still holds once
 * the field's own element is a Matrix block rather than the page entry
 * itself, and specifically whether afterElementSave() attributes the
 * relations it writes to the block (correct) or to the page entry that owns
 * it (would be a bug).
 *
 * Every test here works against the nested block's own Entry element
 * directly (obtained once in `_before()` by bootstrapping a bare block
 * through the host, then reloading it), rather than re-posting the host's
 * Matrix field with a fresh array-form payload each time. That's a
 * deliberate choice, not a stylistic one: `Matrix::_createEntriesFromSerializedData()`
 * resolves a *new* nested entry's field settings via the Matrix field's own
 * `getEntryTypes()` — a different EntryType/FieldLayout/CustomField object
 * graph than the one `Helper\Integration::createMatrixWithSmartTable()`
 * hands back — so mutating the field object this Cest holds has no visible
 * effect on a block created that way. A real, already-persisted nested
 * Entry resolves its own field layout the exact same way a top-level entry
 * does (see `SmartTableFieldCest::fieldOnLayout()`'s docblock), so working
 * against that object directly sidesteps the mismatch entirely — and is
 * exactly what saving a single Matrix block via a direct API call (e.g. a
 * console command touching just that nested entry) would look like anyway.
 *
 * Neo is not covered here: it isn't a require-dev dependency of this plugin
 * (only craftcms/cms + craftcms/ckeditor are), and adding it solely for this
 * one issue isn't a proportionate dependency to take on — see the README's
 * Status section for how that's called out.
 */
class SmartTableFieldMatrixNestingCest
{
    private const MATRIX_HANDLE = 'nestedMatrix';
    private const BLOCK_TYPE_HANDLE = 'nestedBlock';
    private const NESTED_HANDLE = 'nestedSmartTable';
    private const HOST_SECTION_HANDLE = 'matrixHost';

    private Matrix $matrixField;
    private EntryType $blockType;
    private Entry $hostEntry;
    private Entry $nestedEntry;
    private Entry $newsEntry;

    public function _before(IntegrationTester $I): void
    {
        $matrixSetup = $I->createMatrixWithSmartTable(self::MATRIX_HANDLE, self::BLOCK_TYPE_HANDLE, self::NESTED_HANDLE);
        $host = $I->createSectionWithField(self::HOST_SECTION_HANDLE, $matrixSetup['matrixField']);
        $news = $I->createPlainSection('news');

        $this->matrixField = $matrixSetup['matrixField'];
        $this->blockType = $matrixSetup['blockType'];
        $this->hostEntry = $I->createEntry($host, 'Host Entry');
        $this->newsEntry = $I->createEntry($news, 'News Entry');

        // Bootstrap one bare nested block through the host — this is the
        // only place this suite goes through Matrix's array-form
        // setFieldValue() payload, specifically to get a real, persisted
        // nested Entry with a real ID and its own genuinely-resolved field
        // layout. Every test below then works against that entry directly.
        $this->hostEntry->setFieldValue(self::MATRIX_HANDLE, [
            'new1' => ['type' => self::BLOCK_TYPE_HANDLE, 'fields' => []],
        ]);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($this->hostEntry),
            'bootstrap nested block: ' . implode(' ', $this->hostEntry->getErrorSummary(true)),
        );

        $reloadedHost = Entry::find()->id($this->hostEntry->id)->status(null)->one();
        $I->assertNotNull($reloadedHost);
        $nestedEntries = $reloadedHost->getFieldValue(self::MATRIX_HANDLE)->all();
        $I->assertCount(1, $nestedEntries);
        $this->nestedEntry = $nestedEntries[0];
    }

    /**
     * @return array<int, array{sourceId: int, targetId: int, sortOrder: int|null}>
     */
    private function relationsTableRowsFor(int $fieldId, int $sourceId): array
    {
        /** @var array<int, array{sourceId: int, targetId: int, sortOrder: int|null}> */
        return (new Query())
            ->select(['sourceId', 'targetId', 'sortOrder'])
            ->from([DbTable::RELATIONS])
            ->where(['fieldId' => $fieldId, 'sourceId' => $sourceId])
            ->all();
    }

    /**
     * Covers scenario 1 from the issue: a first save of a nested block
     * populates richtext, relations, and native-input (singleline/number/
     * checkbox/date) columns all at once, and every one of them round-trips
     * correctly once read back from a freshly-reloaded nested entry.
     */
    public function initialSavePopulatesRichtextRelationsAndNativeColumnsInNestedBlock(IntegrationTester $I): void
    {
        $field = $I->fieldOnLayout($this->nestedEntry, self::NESTED_HANDLE);
        $field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Notes', 'handle' => 'notes', 'type' => 'richtext'],
            2 => ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number'],
            3 => ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
            4 => ['heading' => 'When', 'handle' => 'when', 'type' => 'date'],
            5 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $field->minRows = null;
        $field->maxRows = null;

        $target = $this->newsEntry;

        $this->nestedEntry->setFieldValue(self::NESTED_HANDLE, [
            'row1' => [
                0 => 'Hello',
                1 => '<p><strong>Bold</strong> note</p>',
                2 => '3',
                3 => '1',
                4 => '2026-01-15',
                5 => [(string)$target->id],
                '_isHeader' => '',
                '_rowClass' => '',
            ],
        ]);

        $I->assertTrue(
            Craft::$app->getElements()->saveElement($this->nestedEntry),
            implode(' ', $this->nestedEntry->getErrorSummary(true)),
        );

        $reloadedNested = Entry::find()->id($this->nestedEntry->id)->status(null)->one();
        $I->assertNotNull($reloadedNested);
        $I->fieldOnLayout($reloadedNested, self::NESTED_HANDLE)->columns = $field->columns;

        $row = $reloadedNested->getFieldValue(self::NESTED_HANDLE)['row1'];

        $I->assertSame('Hello', $row['label']);
        $I->assertSame('<p><strong>Bold</strong> note</p>', $row['notes']);
        // normalizeNumberCell() does `$value + 0`, which PHP resolves to an
        // int for an integer-like numeric string rather than a float.
        $I->assertSame(3, $row['qty']);
        $I->assertTrue($row['active']);
        $I->assertInstanceOf(DateTime::class, $row['when']);
        $I->assertSame('2026-01-15', $row['when']->format('Y-m-d'));
        $I->assertInstanceOf(Entry::class, $row['linked'][0]);
        $I->assertSame($target->id, $row['linked'][0]->id);
    }

    /**
     * Covers scenario 2 from the issue: a second save that only edits the
     * already-existing nested block (adds a row, drops the original row's
     * relation) round-trips correctly.
     */
    public function editingAndResavingNestedBlockRoundTripsCorrectly(IntegrationTester $I): void
    {
        $field = $I->fieldOnLayout($this->nestedEntry, self::NESTED_HANDLE);
        $field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $field->minRows = null;
        $field->maxRows = null;

        $target = $this->newsEntry;

        $this->nestedEntry->setFieldValue(self::NESTED_HANDLE, [
            'row1' => [0 => 'Original', 1 => [(string)$target->id], '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($this->nestedEntry),
            implode(' ', $this->nestedEntry->getErrorSummary(true)),
        );

        // Second save: edit the same nested block in place — add a row and
        // remove the first row's relation.
        $I->fieldOnLayout($this->nestedEntry, self::NESTED_HANDLE)->columns = $field->columns;
        $this->nestedEntry->setFieldValue(self::NESTED_HANDLE, [
            'row1' => [0 => 'Original', 1 => [], '_isHeader' => '', '_rowClass' => ''],
            'row2' => [0 => 'Added', 1 => [(string)$target->id], '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($this->nestedEntry),
            implode(' ', $this->nestedEntry->getErrorSummary(true)),
        );

        $reloadedNested = Entry::find()->id($this->nestedEntry->id)->status(null)->one();
        $I->assertNotNull($reloadedNested);
        $I->fieldOnLayout($reloadedNested, self::NESTED_HANDLE)->columns = $field->columns;

        // Confirm the edit updated the same block rather than creating a
        // second one alongside it.
        $reloadedHost = Entry::find()->id($this->hostEntry->id)->status(null)->one();
        $I->assertNotNull($reloadedHost);
        $I->assertCount(1, $reloadedHost->getFieldValue(self::MATRIX_HANDLE)->all());

        $rows = $reloadedNested->getFieldValue(self::NESTED_HANDLE);
        $I->assertCount(2, $rows);
        $I->assertSame('Original', $rows['row1']['label']);
        $I->assertSame([], $rows['row1']['linked']);
        $I->assertSame('Added', $rows['row2']['label']);
        $I->assertSame($target->id, $rows['row2']['linked'][0]->id);
    }

    /**
     * Covers scenario 3 from the issue: afterElementSave() writes the
     * relations row(s) with sourceId = the nested Matrix block's own element
     * ID, not the host entry's — and relatedTo() finds the block, not the
     * host, as a result. This is the one that would have caught the field
     * misattributing relations to the host entry instead of the block
     * element itself, if that bug existed.
     */
    public function relationsAreScopedToTheNestedBlockNotTheHostEntry(IntegrationTester $I): void
    {
        $field = $I->fieldOnLayout($this->nestedEntry, self::NESTED_HANDLE);
        $field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $field->minRows = null;
        $field->maxRows = null;

        $target = $this->newsEntry;

        $this->nestedEntry->setFieldValue(self::NESTED_HANDLE, [
            'row1' => [0 => [(string)$target->id], '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($this->nestedEntry),
            implode(' ', $this->nestedEntry->getErrorSummary(true)),
        );

        $hostRows = $this->relationsTableRowsFor($field->id, $this->hostEntry->id);
        $I->assertSame([], $hostRows, 'The relations row should be attributed to the nested block, not the host entry.');

        $blockRows = $this->relationsTableRowsFor($field->id, $this->nestedEntry->id);
        $I->assertCount(1, $blockRows);
        $I->assertSame($target->id, $blockRows[0]['targetId']);

        $foundViaBlock = Entry::find()->relatedTo($target)->id($this->nestedEntry->id)->status(null)->one();
        $I->assertNotNull($foundViaBlock, 'Expected relatedTo() to find the nested block via the relations table.');

        $foundViaHost = Entry::find()->relatedTo($target)->id($this->hostEntry->id)->status(null)->one();
        $I->assertNull($foundViaHost, 'The host entry itself has no direct relations row and should not match relatedTo().');
    }
}
