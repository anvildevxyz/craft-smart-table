<?php

declare(strict_types=1);

namespace anvildevxyz\smarttable\tests\Unit;

use anvildevxyz\smarttable\fields\SmartTableField;
use PHPUnit\Framework\TestCase;

class SmartTableFieldTest extends TestCase
{
    public function testDecodesJsonStringFromDb(): void
    {
        $rows = ['row1' => ['col1' => '<b>hi</b>']];
        $decoded = SmartTableField::decodeRows(json_encode($rows));

        $this->assertSame($rows, $decoded);
    }

    public function testPassesThroughAlreadyDecodedArray(): void
    {
        $rows = ['row1' => ['col1' => 'plain text']];

        $this->assertSame($rows, SmartTableField::decodeRows($rows));
    }

    public function testReturnsEmptyArrayForNullOrEmptyString(): void
    {
        $this->assertSame([], SmartTableField::decodeRows(null));
        $this->assertSame([], SmartTableField::decodeRows(''));
    }

    public function testReturnsEmptyArrayForMalformedJson(): void
    {
        $this->assertSame([], SmartTableField::decodeRows('{not json'));
    }

    public function testRelationElementClassResolvesKnownTypes(): void
    {
        $this->assertSame(\craft\elements\Entry::class, SmartTableField::relationElementClass('relations_entries'));
        $this->assertSame(\craft\elements\Asset::class, SmartTableField::relationElementClass('relations_assets'));
        $this->assertSame(\craft\elements\Category::class, SmartTableField::relationElementClass('relations_categories'));
        $this->assertSame(\craft\elements\User::class, SmartTableField::relationElementClass('relations_users'));
    }

    public function testRelationElementClassRejectsUnknownTypes(): void
    {
        $this->assertNull(SmartTableField::relationElementClass('singleline'));
        $this->assertNull(SmartTableField::relationElementClass('not-a-real-type'));
    }

    public function testResolveRestrictedElementsShortCircuitsOnEmptyIds(): void
    {
        // No ids means no query — this is the only path testable without a
        // DB; the non-empty path is covered by the "Duplicate row" live
        // smoke test (docs/smoke-tests) since it needs real saved elements.
        $this->assertSame([], SmartTableField::resolveRestrictedElements(\craft\elements\Entry::class, [], []));
    }

    public function testElementSelectConfigShape(): void
    {
        $config = SmartTableField::elementSelectConfig('input1', 'row1', 2, 'myField', \craft\elements\Entry::class, []);

        $this->assertSame('input1-row1-2', $config['id']);
        $this->assertSame('myField[row1][2]', $config['name']);
        $this->assertSame(\craft\elements\Entry::class, $config['elementType']);
        $this->assertSame([], $config['elements']);
    }

    public function testConstructorIgnoresLegacyShowHeaderColumnSetting(): void
    {
        $field = new SmartTableField([
            'showHeaderColumn' => false,
            'columns' => [['heading' => 'A', 'handle' => 'a', 'type' => 'singleline']],
        ]);

        $this->assertSame('a', $field->columns[0]['handle']);
    }

    private function fieldWithColumns(array $columns): SmartTableField
    {
        return new SmartTableField(['columns' => $columns]);
    }

    public function testValidateColumnsFlagsDuplicateHandles(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'A', 'handle' => 'dup', 'type' => 'singleline'],
            ['heading' => 'B', 'handle' => 'dup', 'type' => 'singleline'],
        ]);

        $field->validateColumns();

        $this->assertTrue($field->hasErrors('columns'));
    }

    public function testValidateColumnsAllowsUniqueHandles(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'A', 'handle' => 'a', 'type' => 'singleline'],
            ['heading' => 'B', 'handle' => 'b', 'type' => 'singleline'],
        ]);

        $field->validateColumns();

        $this->assertFalse($field->hasErrors('columns'));
    }

    public function testValidateColumnsFlagsReservedHandles(): void
    {
        foreach (['isHeader', 'rowClass', '_isHeader', '_rowClass'] as $reserved) {
            $field = $this->fieldWithColumns([
                ['heading' => 'A', 'handle' => $reserved, 'type' => 'singleline'],
            ]);

            $field->validateColumns();

            $this->assertTrue($field->hasErrors('columns'), "Expected \"$reserved\" to be rejected as a column handle.");
        }
    }

    public function testValidateColumnsIgnoresRepeatedEmptyHandles(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'A', 'handle' => '', 'type' => 'singleline'],
            ['heading' => 'B', 'handle' => '', 'type' => 'singleline'],
        ]);

        $field->validateColumns();

        $this->assertFalse($field->hasErrors('columns'));
    }

    public function testNormalizeValueDualIndexesByHandleForNonRelationColumns(): void
    {
        // 'richtext' is deliberately not used here: purifyRichTextCell() needs
        // Yii::$app (see the Integration suite's richtext tests), and this
        // test is only about the generic dual-indexing behavior, not any
        // particular column type's own coercion.
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            ['heading' => 'Contact', 'handle' => 'contact', 'type' => 'email'],
        ]);

        $rows = $field->normalizeValue([
            'row1' => [0 => 'Hello', 1 => 'a@example.com', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $this->assertSame('Hello', $rows['row1']['label']);
        $this->assertSame('Hello', $rows['row1'][0]);
        $this->assertSame('a@example.com', $rows['row1']['contact']);
    }

    public function testNormalizeValueAddsCleanIsHeaderAndRowClassAliases(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);

        $rows = $field->normalizeValue([
            'row1' => [0 => 'Hello', '_isHeader' => '1', '_rowClass' => 'highlight'],
            'row2' => [0 => 'World', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $this->assertTrue($rows['row1']['isHeader']);
        $this->assertSame('highlight', $rows['row1']['rowClass']);
        $this->assertFalse($rows['row2']['isHeader']);
        $this->assertSame('', $rows['row2']['rowClass']);

        // The raw underscore-prefixed keys stay too, for any existing
        // template already reading them.
        $this->assertSame('1', $rows['row1']['_isHeader']);
        $this->assertSame('highlight', $rows['row1']['_rowClass']);
    }

    public function testNormalizeValueDropsNonArrayRows(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);

        $rows = $field->normalizeValue(['row1' => 'not an array'], null);

        $this->assertSame([], $rows);
    }

    public function testNormalizeValueReturnsEmptyArrayForEmptyInput(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);

        $this->assertSame([], $field->normalizeValue(null, null));
        $this->assertSame([], $field->normalizeValue('', null));
    }

    public function testSerializeValueKeepsOnlyNumericAndReservedKeys(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);

        // Shape normalizeValue() would have produced: numeric key 0 plus the
        // handle-keyed duplicate at 'label'.
        $rows = $field->serializeValue([
            'row1' => [0 => 'Hello', 'label' => 'Hello', '_isHeader' => '1', '_rowClass' => 'foo'],
        ], null);

        $this->assertSame([
            'row1' => ['_isHeader' => '1', '_rowClass' => 'foo', 0 => 'Hello'],
        ], $rows);
    }

    public function testSerializeValueDefaultsMissingRowMetadata(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);

        $rows = $field->serializeValue(['row1' => [0 => 'Hello']], null);

        $this->assertSame('', $rows['row1']['_isHeader']);
        $this->assertSame('', $rows['row1']['_rowClass']);
    }

    public function testSerializeValueCastsRelationCellsToIntIds(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ]);

        $rows = $field->serializeValue([
            'row1' => [0 => ['5', '9'], '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $this->assertSame([5, 9], $rows['row1'][0]);
    }

    public function testSerializeValueSkipsNonArrayRows(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);

        $rows = $field->serializeValue(['row1' => 'not an array'], null);

        $this->assertSame([], $rows);
    }

    public function testSerializeValueReturnsNullForNonArrayInput(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);

        $this->assertNull($field->serializeValue('not an array', null));
    }

    public function testNormalizeValueCoercesCheckboxCellToBool(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
        ]);

        $rows = $field->normalizeValue([
            'row1' => [0 => '1', '_isHeader' => '', '_rowClass' => ''],
            'row2' => [0 => '', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $this->assertTrue($rows['row1']['active']);
        $this->assertFalse($rows['row2']['active']);
    }

    /**
     * A GraphQL mutation's row (see getContentGqlMutationArgumentType())
     * can only ever be handle-keyed — GraphQL field names can't start with
     * a digit, so there's no numeric-colId equivalent to send — this is
     * the pure-PHP core of that support: normalizeValue() reading a cell by
     * handle when the numeric colId isn't present at all. (Real end-to-end
     * GraphQL mutation execution is covered in the Integration suite,
     * where Craft::$app is available to actually run one.)
     */
    public function testNormalizeValueAcceptsHandleKeyedCellsWithNoNumericColId(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
        ]);

        $rows = $field->normalizeValue([
            'row1' => ['label' => 'Hello', 'active' => '1', 'isHeader' => true, 'rowClass' => 'gql-row'],
        ], null);

        $this->assertSame('Hello', $rows['row1']['label']);
        $this->assertSame('Hello', $rows['row1'][0]);
        $this->assertTrue($rows['row1']['active']);
        $this->assertTrue($rows['row1'][1]);
        $this->assertTrue($rows['row1']['isHeader']);
        $this->assertSame('gql-row', $rows['row1']['rowClass']);
        // serializeValue() only ever reads the underscored keys — without
        // these also being written back, isHeader/rowClass would resolve
        // correctly here but silently fail to persist.
        $this->assertSame('1', $rows['row1']['_isHeader']);
        $this->assertSame('gql-row', $rows['row1']['_rowClass']);
    }

    public function testNormalizeValueCoercesNumberCellToNumeric(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number'],
        ]);

        $rows = $field->normalizeValue([
            'row1' => [0 => '42', '_isHeader' => '', '_rowClass' => ''],
            'row2' => [0 => '3.5', '_isHeader' => '', '_rowClass' => ''],
            'row3' => [0 => 'not a number', '_isHeader' => '', '_rowClass' => ''],
            'row4' => [0 => '', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $this->assertSame(42, $rows['row1']['qty']);
        $this->assertSame(3.5, $rows['row2']['qty']);
        $this->assertNull($rows['row3']['qty']);
        $this->assertNull($rows['row4']['qty']);
    }

    public function testNormalizeValuePassesThroughAlreadyNormalizedDateCell(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'When', 'handle' => 'when', 'type' => 'date'],
        ]);
        $date = new \DateTime('2026-01-01');

        $rows = $field->normalizeValue([
            'row1' => [0 => $date, '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $this->assertSame($date, $rows['row1']['when']);
    }

    public function testSerializeValueConvertsDateTimeToIsoString(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'When', 'handle' => 'when', 'type' => 'date'],
        ]);
        $date = new \DateTime('2026-01-01T00:00:00+00:00');

        $rows = $field->serializeValue([
            'row1' => [0 => $date, '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $this->assertSame($date->format(\DateTime::ATOM), $rows['row1'][0]);
    }

    public function testSerializeValuePassesThroughCheckboxAndNumberCells(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
            ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number'],
        ]);

        $rows = $field->serializeValue([
            'row1' => [0 => true, 1 => 3.5, '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $this->assertTrue($rows['row1'][0]);
        $this->assertSame(3.5, $rows['row1'][1]);
    }

    /**
     * selectOptions() is private and pure (no Craft:: dependency), so it's
     * invoked directly via reflection rather than through inputHtml() (which
     * needs a booted app for Craft::$app->getView()).
     */
    public function testSelectOptionsParsesCommaSeparatedList(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Color', 'handle' => 'color', 'type' => 'select', 'options' => 'Red, Green ,  Blue'],
        ]);

        $method = new \ReflectionMethod(SmartTableField::class, 'selectOptions');
        $options = $method->invoke($field, $field->columns[0]);

        $this->assertSame(['Red' => 'Red', 'Green' => 'Green', 'Blue' => 'Blue'], $options);
    }

    public function testSelectOptionsReturnsEmptyArrayWhenUnset(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Color', 'handle' => 'color', 'type' => 'select'],
        ]);

        $method = new \ReflectionMethod(SmartTableField::class, 'selectOptions');
        $options = $method->invoke($field, $field->columns[0]);

        $this->assertSame([], $options);
    }

    /**
     * Pure string parsing (no Craft:: dependency, unlike sourceIdByHandle(),
     * which needs a booted app's section/volume/group services — see the
     * Integration suite for that half).
     */
    public function testSourceHandlesParsesCommaSeparatedList(): void
    {
        $method = new \ReflectionMethod(SmartTableField::class, 'sourceHandles');
        $handles = $method->invoke(null, ['source' => 'news, pages ,  blog']);

        $this->assertSame(['news', 'pages', 'blog'], $handles);
    }

    public function testSourceHandlesReturnsEmptyArrayWhenUnset(): void
    {
        $method = new \ReflectionMethod(SmartTableField::class, 'sourceHandles');

        $this->assertSame([], $method->invoke(null, []));
        $this->assertSame([], $method->invoke(null, ['source' => '']));
        $this->assertSame([], $method->invoke(null, ['source' => '  ']));
    }

    /**
     * The settings screen's multi-select posts `source` as an array (see
     * SmartTableField::sourceCellHtml()), not the legacy comma-separated
     * string — sourceHandles() has to accept either shape.
     */
    public function testSourceHandlesParsesArrayInput(): void
    {
        $method = new \ReflectionMethod(SmartTableField::class, 'sourceHandles');
        $handles = $method->invoke(null, ['source' => ['news', ' pages ', '', 'blog']]);

        $this->assertSame(['news', 'pages', 'blog'], $handles);
    }

    public function testSourceHandlesReturnsEmptyArrayForEmptyArrayInput(): void
    {
        $method = new \ReflectionMethod(SmartTableField::class, 'sourceHandles');

        $this->assertSame([], $method->invoke(null, ['source' => []]));
    }

    /**
     * Must match the toolbar every Rich text column showed before the Rich
     * Text Toolbar setting existed, so a field with no source field chosen
     * (the default state, including every already-saved field predating
     * this setting) sees no behavior change. This process never boots a
     * Craft app, so Craft::$app->getFields() is never reached here — exactly
     * the "no source field chosen" path resolvedToolbar() falls back from;
     * the "real source field, filtered to supported items" path is covered
     * by the integration suite instead, against an actual native CKEditor
     * field.
     */
    public function testResolvedToolbarDefaultsToTheLegacyHardcodedToolbar(): void
    {
        $field = new SmartTableField();
        $method = new \ReflectionMethod(SmartTableField::class, 'resolvedToolbar');

        $this->assertSame(['bold', 'italic', 'link', 'bulletedList'], $method->invoke($field));
    }

    /**
     * relationPickerRestriction() is public and short-circuits before
     * touching Craft::$app when there's no source configured — the one
     * case unit-testable without a booted app.
     */
    public function testRelationPickerRestrictionReturnsEmptyWhenSourceUnset(): void
    {
        $empty = ['criteria' => [], 'sources' => []];
        $this->assertSame($empty, SmartTableField::relationPickerRestriction(\craft\elements\Entry::class, []));
        $this->assertSame($empty, SmartTableField::relationPickerRestriction(\craft\elements\Entry::class, ['source' => '']));
    }

    /**
     * Only the empty-rows early return is unit-testable: every other branch
     * calls Craft::t() with an ICU plural message, which needs a booted
     * app's real MessageFormatter to render correctly (the plain-PHP
     * fallback tests/bootstrap.php sets up only does a bare strtr() on
     * `{name}` placeholders — see the Integration suite for real output).
     */
    public function testGetPreviewHtmlReturnsEmptyStringForNoRows(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);
        $element = $this->createMock(\craft\base\ElementInterface::class);

        $this->assertSame('', $field->getPreviewHtml([], $element));
        $this->assertSame('', $field->getPreviewHtml('not an array', $element));
    }

    public function testFirstTextColumnIdSkipsNonTextTypes(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number'],
            ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
            ['heading' => 'Notes', 'handle' => 'notes', 'type' => 'richtext'],
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ]);

        $method = new \ReflectionMethod(SmartTableField::class, 'firstTextColumnId');
        $this->assertSame(2, $method->invoke($field));
    }

    public function testFirstTextColumnIdReturnsNullWhenNoneAreTextLike(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number'],
            ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
            ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ]);

        $method = new \ReflectionMethod(SmartTableField::class, 'firstTextColumnId');
        $this->assertNull($method->invoke($field));
    }

    /**
     * rowsForExport()/rowsFromImport()/rowsToCsv()/rowsFromCsv() are all
     * pure PHP (no Craft:: dependency), unlike the field methods that
     * actually resolve relations or persist — those round-trip through a
     * real element in the Integration suite instead.
     */
    private function fieldForExport(): SmartTableField
    {
        return $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
            ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ]);
    }

    public function testRowsForExportKeysByHandleAndSkipsColumnsWithNoHandle(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            ['heading' => 'No handle', 'handle' => '', 'type' => 'singleline'],
        ]);

        $exported = $field->rowsForExport([
            'row1' => [0 => 'Hello', 1 => 'ignored', '_isHeader' => '1', '_rowClass' => 'foo'],
        ]);

        $this->assertSame([
            ['isHeader' => true, 'rowClass' => 'foo', 'label' => 'Hello'],
        ], $exported);
    }

    public function testRowsForExportFormatsDateAsIso8601(): void
    {
        $field = $this->fieldWithColumns([
            ['heading' => 'When', 'handle' => 'when', 'type' => 'date'],
        ]);
        $date = new \DateTime('2026-01-15T00:00:00+00:00');

        $exported = $field->rowsForExport([
            'row1' => [0 => $date, '_isHeader' => '', '_rowClass' => ''],
        ]);

        $this->assertSame($date->format(\DateTime::ATOM), $exported[0]['when']);
    }

    public function testRowsFromImportRebuildsNumericColIdShape(): void
    {
        $field = $this->fieldForExport();

        $raw = $field->rowsFromImport([
            ['isHeader' => true, 'rowClass' => 'foo', 'label' => 'Hello', 'active' => true, 'linked' => [5, 9]],
        ]);

        $this->assertSame([
            ['_isHeader' => '1', '_rowClass' => 'foo', 0 => 'Hello', 1 => true, 2 => [5, 9]],
        ], $raw);
    }

    public function testRowsFromImportSkipsNonArrayRows(): void
    {
        $field = $this->fieldForExport();

        $this->assertSame([], $field->rowsFromImport(['not an array']));
    }

    public function testRowsToCsvAndRowsFromCsvRoundTrip(): void
    {
        $field = $this->fieldForExport();

        $exported = [
            ['isHeader' => true, 'rowClass' => 'section', 'label' => 'Intro', 'active' => false, 'linked' => []],
            ['isHeader' => false, 'rowClass' => '', 'label' => 'Row "two"', 'active' => true, 'linked' => [5, 9]],
        ];

        $csv = $field->rowsToCsv($exported);
        $roundTripped = $field->rowsFromCsv($csv);

        $this->assertSame([
            ['isHeader' => true, 'rowClass' => 'section', 'label' => 'Intro', 'active' => '', 'linked' => []],
            ['isHeader' => false, 'rowClass' => '', 'label' => 'Row "two"', 'active' => '1', 'linked' => ['5', '9']],
        ], $roundTripped);
    }

    public function testRowsFromCsvReturnsEmptyArrayForEmptyString(): void
    {
        $field = $this->fieldForExport();

        $this->assertSame([], $field->rowsFromCsv(''));
    }

    public function testRowsFromCsvThrowsOnLineWithWrongColumnCount(): void
    {
        $field = $this->fieldForExport();

        $this->expectException(\yii\base\InvalidArgumentException::class);

        $field->rowsFromCsv("isHeader,rowClass,label,active,linked\n1,section,Intro,\n");
    }

    public function testRowsFromCsvIgnoresTrailingBlankLine(): void
    {
        $field = $this->fieldForExport();

        $rows = $field->rowsFromCsv("isHeader,rowClass,label,active,linked\n1,section,Intro,,\n\n");

        $this->assertSame([
            ['isHeader' => true, 'rowClass' => 'section', 'label' => 'Intro', 'active' => '', 'linked' => []],
        ], $rows);
    }

    public function testRowsFromCsvIgnoresLeadingBlankLine(): void
    {
        $field = $this->fieldForExport();

        $rows = $field->rowsFromCsv("\nisHeader,rowClass,label,active,linked\n1,section,Intro,,\n");

        $this->assertSame([
            ['isHeader' => true, 'rowClass' => 'section', 'label' => 'Intro', 'active' => '', 'linked' => []],
        ], $rows);
    }

    public function testRowsFromCsvImportsBlankLineAsEmptyValueWhenColumnCountMatches(): void
    {
        $field = $this->fieldForExport();

        // A blank line and a genuinely empty one-column value are the same
        // bytes; only a header this narrow makes that line's column count
        // (1) match the header's (1), so it's read as data, not skipped.
        $rows = $field->rowsFromCsv("label\n\nSomething\n");

        $this->assertSame([
            ['label' => '', 'isHeader' => false],
            ['label' => 'Something', 'isHeader' => false],
        ], $rows);
    }

    public function testRelationCellToIdsThrowsOnNonScalarNonElementEntry(): void
    {
        $field = $this->fieldForExport();

        $this->expectException(\yii\base\InvalidArgumentException::class);

        $field->serializeValue([
            'row1' => [2 => [['unexpected' => 'shape']], '_isHeader' => '', '_rowClass' => ''],
        ], null);
    }

    public function testAssertImportColumnsMatchAllowsAMatchingImport(): void
    {
        $field = $this->fieldForExport();

        $field->assertImportColumnsMatch([
            ['isHeader' => false, 'rowClass' => '', 'label' => 'Hello', 'active' => true, 'linked' => []],
        ]);

        $this->assertTrue(true);
    }

    public function testAssertImportColumnsMatchAllowsAnEmptyImport(): void
    {
        $field = $this->fieldForExport();

        // Replacing a field's rows with zero rows is a valid, deliberate
        // "clear the table" import — there's no header to validate against.
        $field->assertImportColumnsMatch([]);

        $this->assertTrue(true);
    }

    public function testAssertImportColumnsMatchThrowsOnMissingHandle(): void
    {
        $field = $this->fieldForExport();

        $this->expectException(\yii\base\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing column(s): active');

        $field->assertImportColumnsMatch([
            ['isHeader' => false, 'rowClass' => '', 'label' => 'Hello', 'linked' => []],
        ]);
    }

    public function testAssertImportColumnsMatchThrowsOnUnknownHandle(): void
    {
        $field = $this->fieldForExport();

        $this->expectException(\yii\base\InvalidArgumentException::class);
        $this->expectExceptionMessage('unrecognized column(s): status');

        $field->assertImportColumnsMatch([
            ['isHeader' => false, 'rowClass' => '', 'label' => 'Hello', 'active' => true, 'linked' => [], 'status' => 'draft'],
        ]);
    }

    public function testAssertImportColumnsMatchChecksTheUnionOfEveryRowsKeys(): void
    {
        $field = $this->fieldForExport();

        // A short row on its own would otherwise look like it's missing
        // "linked" — checked against every row's keys combined, not just
        // the first row's, this is a valid import.
        $field->assertImportColumnsMatch([
            ['isHeader' => false, 'rowClass' => '', 'label' => 'Hello', 'active' => true, 'linked' => [5]],
            ['isHeader' => false, 'rowClass' => '', 'label' => 'World', 'active' => false],
        ]);

        $this->assertTrue(true);
    }

    public function testAssertImportColumnsMatchIgnoresNonArrayRowsWhenCollectingKeys(): void
    {
        $field = $this->fieldForExport();

        // The stray non-array entry contributes no keys of its own; it
        // shouldn't stop a real row's matching keys from being recognized.
        $field->assertImportColumnsMatch([
            'not an array',
            ['isHeader' => false, 'rowClass' => '', 'label' => 'Hello', 'active' => true, 'linked' => []],
        ]);

        $this->assertTrue(true);
    }
}
