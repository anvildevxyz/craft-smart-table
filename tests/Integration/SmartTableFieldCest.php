<?php

namespace anvildevxyz\smarttable\tests\integration;

use anvildevxyz\smarttable\fields\SmartTableField;
use anvildevxyz\smarttable\gql\SmartTableRowType;
use Craft;
use craft\db\Query;
use craft\db\Table as DbTable;
use craft\elements\Entry;
use craft\gql\GqlEntityRegistry;
use craft\models\GqlSchema;
use DateTime;
use GraphQL\Type\Definition\ListOfType;
use IntegrationTester;

/**
 * Exercises the parts of SmartTableField that a plain-PHPUnit unit test can't
 * reach because they need a booted Craft app: validation against a real
 * element, relation-column resolution via a real element query, and GraphQL
 * type generation.
 *
 * Every fixture (the `smartTableTest` section + field, the unrelated `news`
 * and `pages` sections) is built fresh by Helper\Integration and cached
 * in-process for the whole suite run — see that class's own docblock. Each
 * test gets its own freshly-saved entry in every section via _before(), rolled
 * back afterward by the `transaction: true` module setting, so tests can't
 * see each other's entry content.
 */
class SmartTableFieldCest
{
    private const HANDLE = 'smartTableTest';

    private SmartTableField $field;
    private Entry $entry;
    private Entry $newsEntry;
    private Entry $pagesEntry;

    public function _before(IntegrationTester $I): void
    {
        $primary = $I->createSmartTableSection(self::HANDLE);
        $news = $I->createPlainSection('news');
        $pages = $I->createPlainSection('pages');

        $this->field = $primary['field'];
        $this->entry = $I->createEntry($primary, 'Test Entry');
        $this->newsEntry = $I->createEntry($news, 'News Entry');
        $this->pagesEntry = $I->createEntry($pages, 'Pages Entry');

        // Deterministic baseline: $this->field is a process-lifetime-cached
        // instance (see createSmartTableSection()'s docblock), reused by
        // every test in this suite — reset every setting a test might mutate
        // so one test's configuration can never leak into another's.
        $this->field->columns = [0 => ['heading' => '', 'handle' => '', 'type' => 'singleline']];
        $this->field->minRows = null;
        $this->field->maxRows = null;
        $this->field->name = 'Smart Table';
        $this->field->toolbarSourceFieldUid = null;
        $this->field->clearErrors();
    }

    /**
     * $this->field (from createSmartTableSection(), at section-creation time)
     * and a real entry's own $entry->getFieldLayout()->getFieldByHandle() can
     * be two independently cached instances of the same field —
     * normalizeValue()/validateRows() calls that go through
     * $element->getFieldValue() resolve the field via the *layout* path, not
     * necessarily $this->field, so a test that mutates $this->field->columns
     * and then expects that to affect $entry->getFieldValue()/validateRows()
     * needs this to actually see it. Confirmed against a real dev project via
     * direct object-identity inspection (spl_object_id()) that these can be
     * genuinely separate objects, not a stale-value red herring — most tests
     * in this suite that already do "mutate columns, then
     * getFieldValue()/validateRows()" happen not to notice, because their
     * assertions (an empty-string check) don't depend on which column *type*
     * was used, only whether a raw scalar is empty. Only the relation-source
     * tests below actually need this.
     */
    private function syncFieldToEntryLayout(IntegrationTester $I, Entry $entry): void
    {
        $I->fieldOnLayout($entry, self::HANDLE)->columns = $this->field->columns;
    }

    public function validateRowsFlagsMissingRequiredCell(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline', 'required' => true],
        ];

        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => '', '_isHeader' => '', '_rowClass' => ''],
        ]);

        $this->field->validateRows($this->entry);

        $I->assertTrue($this->entry->hasErrors(self::HANDLE));
    }

    public function validateRowsPassesWhenRequiredCellIsFilled(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline', 'required' => true],
        ];

        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Hello', '_isHeader' => '', '_rowClass' => ''],
        ]);

        $this->field->validateRows($this->entry);

        $I->assertFalse($this->entry->hasErrors(self::HANDLE));
    }

    public function validateRowsEnforcesMinRows(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ];
        $this->field->minRows = 2;

        $this->entry->setFieldValue(self::HANDLE, []);

        $this->field->validateRows($this->entry);

        $I->assertTrue($this->entry->hasErrors(self::HANDLE));
    }

    public function normalizeValueResolvesRelationIdsToRealElements(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];

        $target = $this->entry;

        $rows = $this->field->normalizeValue([
            'row1' => [0 => [(string)$target->id], '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $I->assertInstanceOf(Entry::class, $rows['row1'][0][0]);
        $I->assertSame($target->id, $rows['row1'][0][0]->id);
        // Handle-keyed dual-indexing should resolve to the same real element.
        $I->assertSame($target->id, $rows['row1']['linked'][0]->id);
    }

    /**
     * inputHtml() is protected — Field::getInputHtml() (the public,
     * validation-error-aware entry point real CP rendering goes through)
     * pulls in more machinery (field-layout element context, etc.) than this
     * needs; calling the field's own method directly is enough to check what
     * column shows up in the rendered markup.
     *
     * Header rows are marked from the row action menu, not a grid checkbox.
     */
    public function inputHtmlNeverRendersAHeaderCheckboxColumn(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ];

        $method = new \ReflectionMethod(SmartTableField::class, 'inputHtml');
        $html = $method->invoke($this->field, [
            'row1' => [0 => 'Hello', '_isHeader' => '1', '_rowClass' => ''],
        ], null, false);

        $I->assertStringNotContainsString('Header?', $html);
        $I->assertStringNotContainsString('smarttable-is-header-col', $html);
        $I->assertMatchesRegularExpression('/name="[^"]*\[row1\]\[_isHeader\]"[^>]*value="1"/', $html);
    }

    public function inputHtmlEmbedsTheChosenSourceFieldsToolbarFilteredToSupportedItems(IntegrationTester $I): void
    {
        // 'heading' and 'insertImage' need per-button config (heading levels,
        // an asset-transform list) this field doesn't supply — resolvedToolbar()
        // must drop them rather than ship them broken.
        $sourceField = $I->createCkeditorField('toolbarSourceMixed', ['bold', 'heading', 'code', 'insertImage']);
        $this->field->columns = [
            0 => ['heading' => 'Details', 'handle' => 'details', 'type' => 'richtext'],
        ];
        $this->field->toolbarSourceFieldUid = $sourceField->uid;

        $method = new \ReflectionMethod(SmartTableField::class, 'inputHtml');
        $html = $method->invoke($this->field, [], null, false);

        $this->assertToolbarScript($I, $html, ['bold', 'code']);
    }

    public function inputHtmlFallsBackToTheDefaultToolbarWhenNoSourceFieldIsChosen(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Details', 'handle' => 'details', 'type' => 'richtext'],
        ];
        $this->field->toolbarSourceFieldUid = null;

        $method = new \ReflectionMethod(SmartTableField::class, 'inputHtml');
        $html = $method->invoke($this->field, [], null, false);

        $this->assertToolbarScript($I, $html, ['bold', 'italic', 'link', 'bulletedList']);
    }

    public function inputHtmlFallsBackToTheDefaultToolbarWhenTheChosenSourceFieldWasDeleted(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Details', 'handle' => 'details', 'type' => 'richtext'],
        ];
        $this->field->toolbarSourceFieldUid = 'not-a-real-uid';

        $method = new \ReflectionMethod(SmartTableField::class, 'inputHtml');
        $html = $method->invoke($this->field, [], null, false);

        $this->assertToolbarScript($I, $html, ['bold', 'italic', 'link', 'bulletedList']);
    }

    /**
     * Asserts inputHtml() embedded exactly one `data-smarttable-toolbar`
     * `<script>` tag, decoding to $expected.
     */
    private function assertToolbarScript(IntegrationTester $I, string $html, array $expected): void
    {
        $matched = preg_match('#<script type="application/json"[^>]*data-smarttable-toolbar[^>]*>(.*?)</script>#s', $html, $matches);
        $I->assertSame(1, $matched, 'Expected an embedded toolbar <script> tag in inputHtml() output.');
        $I->assertSame($expected, json_decode($matches[1], true));
    }

    public function inputHtmlOmitsTheToolbarScriptWhenNoRichtextColumnExists(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ];

        $method = new \ReflectionMethod(SmartTableField::class, 'inputHtml');
        $html = $method->invoke($this->field, [], null, false);

        $I->assertStringNotContainsString('data-smarttable-toolbar', $html);
    }

    public function getSettingsHtmlRendersTheToolbarSourceDropdownWithTheChosenFieldSelected(IntegrationTester $I): void
    {
        $sourceField = $I->createCkeditorField('toolbarSourceDropdown', ['bold', 'link']);
        $this->field->toolbarSourceFieldUid = $sourceField->uid;

        $html = $this->field->getSettingsHtml();

        $I->assertStringContainsString('Rich Text Toolbar', $html);

        $matched = preg_match('/<option[^>]*value="' . preg_quote($sourceField->uid, '/') . '"[^>]*>/', $html, $chosenOption);
        $I->assertSame(1, $matched, 'Expected an <option> for the chosen source field.');
        $I->assertStringContainsString('selected', $chosenOption[0]);

        $matched = preg_match('/<option[^>]*value=""[^>]*>/', $html, $defaultOption);
        $I->assertSame(1, $matched, 'Expected a "Default" <option> with an empty value.');
        $I->assertStringNotContainsString('selected', $defaultOption[0]);
    }

    /**
     * Without a matching <option>, no <option> gets `selected`, the browser
     * silently falls back to the first one ("Default"), and that gets
     * posted back on the very next save — silently clearing a still-valid
     * (if unresolvable) setting. getSettingsHtml() must keep the stale UID
     * selectable so an unrelated save doesn't wipe it.
     */
    public function getSettingsHtmlKeepsADeletedToolbarSourceFieldSelectedInsteadOfSilentlyResettingIt(IntegrationTester $I): void
    {
        $this->field->toolbarSourceFieldUid = 'a-deleted-field-uid';

        $html = $this->field->getSettingsHtml();

        $matched = preg_match('/<option[^>]*value="a-deleted-field-uid"[^>]*>/', $html, $missingOption);
        $I->assertSame(1, $matched, 'Expected a placeholder <option> for the deleted source field.');
        $I->assertStringContainsString('selected', $missingOption[0]);

        $matched = preg_match('/<option[^>]*value=""[^>]*>/', $html, $defaultOption);
        $I->assertSame(1, $matched);
        $I->assertStringNotContainsString('selected', $defaultOption[0]);
    }

    public function validateColumnsFlagsUnresolvableSourceHandle(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries', 'source' => 'not-a-real-section'],
        ];

        $this->field->validateColumns();

        $I->assertTrue($this->field->hasErrors('columns'));
    }

    public function validateColumnsAllowsResolvableSourceHandle(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries', 'source' => 'news'],
        ];

        $this->field->validateColumns();

        $I->assertFalse($this->field->hasErrors('columns'));
    }

    /**
     * getSettingsHtml()'s "Relation source" cells now post as an array (the
     * settings screen's checkbox list — see SmartTableField::sourceCellHtml())
     * rather than the legacy comma-separated string; validateColumns() must
     * behave identically either way.
     */
    public function validateColumnsFlagsUnresolvableSourceHandleFromArrayInput(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries', 'source' => ['not-a-real-section']],
        ];

        $this->field->validateColumns();

        $I->assertTrue($this->field->hasErrors('columns'));
    }

    public function validateColumnsAllowsResolvableSourceHandleFromArrayInput(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries', 'source' => ['news']],
        ];

        $this->field->validateColumns();

        $I->assertFalse($this->field->hasErrors('columns'));
    }

    /**
     * getSettingsHtml() builds the "Relation source" cell as a real
     * checkbox list of actual section names (SmartTableField::sourceCellHtml()/
     * allSourcesFor()) rather than the old free-text handle input, and embeds
     * every relations type's full source list as JSON for the settings-
     * screen JS to build/rebuild that checklist per row without an AJAX
     * round trip (SmartTableField::relationSourcesPayload()).
     */
    public function getSettingsHtmlRendersRelationSourceAsAChecklistWithRealSourceNames(IntegrationTester $I): void
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('news');
        $I->assertNotNull($section, 'Expected a "news" section to exist in this project.');

        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries', 'source' => 'news'],
        ];

        $html = $this->field->getSettingsHtml();

        $I->assertStringContainsString('data-smarttable-settings-type-col', $html);
        $I->assertStringContainsString('data-smarttable-settings-options-col', $html);
        $I->assertStringContainsString('data-smarttable-settings-source-col', $html);
        $I->assertStringContainsString('data-smarttable-sources', $html);
        $I->assertStringContainsString('checkbox-select', $html);

        $matched = preg_match('/<input[^>]*value="news"[^>]*>/', $html, $inputMatch);
        $I->assertSame(1, $matched, 'Expected a checkbox input for the "news" source.');
        $I->assertStringContainsString('checked', $inputMatch[0], 'Expected the "news" checkbox to be checked.');

        $matched = preg_match('#<script type="application/json"[^>]*data-smarttable-sources[^>]*>(.*?)</script>#s', $html, $matches);
        $I->assertSame(1, $matched, 'Expected an embedded sources <script> tag in getSettingsHtml() output.');
        $payload = json_decode($matches[1], true);
        $I->assertArrayHasKey('relations_entries', $payload);
        $handles = array_column($payload['relations_entries'], 'handle');
        $I->assertContains('news', $handles);
        $I->assertContains('pages', $handles);
    }

    /**
     * A handle whose section/volume/group has since been deleted has no
     * real checkbox to represent it — without one, it's simply absent from
     * the next POST, silently downgrading a restricted column to
     * unrestricted the next time this field's settings are saved for any
     * reason. sourceCellHtml() must add a placeholder checkbox so it stays
     * checked (and visibly flagged) unless deliberately unchecked.
     */
    public function getSettingsHtmlKeepsADeletedRelationSourceHandleCheckedInsteadOfSilentlyDroppingIt(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries', 'source' => 'a-deleted-section'],
        ];

        $html = $this->field->getSettingsHtml();

        $matched = preg_match('/<input[^>]*value="a-deleted-section"[^>]*>/', $html, $inputMatch);
        $I->assertSame(1, $matched, 'Expected a placeholder checkbox for the deleted section handle.');
        $I->assertStringContainsString('checked', $inputMatch[0]);
    }

    /**
     * Both halves matter, not just criteria — see elementSelectConfig()'s
     * docblock for the SQL error a criteria-only restriction produces when
     * the sidebar's default source is still a structure section.
     */
    public function relationPickerRestrictionBuildsCriteriaAndSourcesFromSourceHandle(IntegrationTester $I): void
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('news');
        $I->assertNotNull($section, 'Expected a "news" section to exist in this project.');

        $restriction = SmartTableField::relationPickerRestriction(Entry::class, ['source' => 'news']);

        $I->assertSame(['sectionId' => [$section->id]], $restriction['criteria']);
        $I->assertSame(["section:{$section->uid}"], $restriction['sources']);
    }

    /**
     * Picker-side restriction (relationPickerRestriction(), exercised above)
     * is a client-side courtesy; this is the actual server-side enforcement —
     * a direct POST naming an out-of-scope element still gets rejected.
     */
    public function validateRowsFlagsElementOutsideConfiguredSource(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries', 'source' => 'news'],
        ];

        $outOfScope = $this->pagesEntry;
        $this->syncFieldToEntryLayout($I, $this->entry);
        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => [(string)$outOfScope->id], '_isHeader' => '', '_rowClass' => ''],
        ]);

        $this->field->validateRows($this->entry);

        $I->assertTrue($this->entry->hasErrors(self::HANDLE));
    }

    public function validateRowsAllowsElementWithinConfiguredSource(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries', 'source' => 'news'],
        ];

        $inScope = $this->newsEntry;
        $this->syncFieldToEntryLayout($I, $this->entry);
        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => [(string)$inScope->id], '_isHeader' => '', '_rowClass' => ''],
        ]);

        $this->field->validateRows($this->entry);

        $I->assertFalse($this->entry->hasErrors(self::HANDLE));
    }

    /**
     * DateTimeHelper::toDateTime() needs Craft::$app (locale resolution for
     * parsing), so unlike checkbox/number coercion (plain PHP casts, covered
     * in the Unit suite), a real raw-string-to-DateTime parse can only be
     * tested here.
     */
    public function normalizeValueParsesRawDateStringIntoDateTime(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'When', 'handle' => 'when', 'type' => 'date'],
        ];

        $rows = $this->field->normalizeValue([
            'row1' => [0 => '2026-01-15', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $I->assertInstanceOf(DateTime::class, $rows['row1']['when']);
        $I->assertSame('2026-01-15', $rows['row1']['when']->format('Y-m-d'));
    }

    /**
     * craft\helpers\HtmlPurifier::process() reads Yii::$app->getRuntimePath()
     * unconditionally, so like the date-parsing test above, this can only run
     * with a booted Craft app.
     */
    public function normalizeValueStripsScriptTagsFromRichTextCell(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Body', 'handle' => 'body', 'type' => 'richtext'],
        ];

        $rows = $this->field->normalizeValue([
            'row1' => [0 => '<p>Hi</p><script>alert(1)</script>', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $I->assertSame('<p>Hi</p>', $rows['row1']['body']);
    }

    public function normalizeValueStripsEventHandlerAttributesFromRichTextCell(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Body', 'handle' => 'body', 'type' => 'richtext'],
        ];

        $rows = $this->field->normalizeValue([
            'row1' => [0 => '<img src="x" onerror="alert(1)">', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $I->assertStringNotContainsString('onerror', $rows['row1']['body']);
    }

    public function normalizeValuePreservesAllowedRichTextMarkup(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Body', 'handle' => 'body', 'type' => 'richtext'],
        ];

        $rows = $this->field->normalizeValue([
            'row1' => [0 => '<p><strong>Bold</strong> and <a href="https://example.com">a link</a></p>', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $I->assertSame('<p><strong>Bold</strong> and <a href="https://example.com">a link</a></p>', $rows['row1']['body']);
    }

    /**
     * getPreviewHtml()'s ICU-pluralized row-count fallback and the "+N more"
     * suffix both go through Craft::t(), which only formats an ICU plural
     * message correctly with a real, booted app's MessageFormatter — the Unit
     * suite's plain-PHP Craft::t() fallback can't exercise this.
     */
    public function getPreviewHtmlSummarizesFirstTextColumnAcrossRows(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number'],
        ];

        $rows = $this->field->normalizeValue([
            'row1' => [0 => 'Alpha', 1 => '1', '_isHeader' => '', '_rowClass' => ''],
            'row2' => [0 => 'Beta', 1 => '2', '_isHeader' => '', '_rowClass' => ''],
            'row3' => [0 => 'Gamma', 1 => '3', '_isHeader' => '', '_rowClass' => ''],
            'row4' => [0 => 'Delta', 1 => '4', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $html = $this->field->getPreviewHtml($rows, $this->entry);

        $I->assertSame('Alpha, Beta, Gamma, +1 more', $html);
    }

    public function getPreviewHtmlStripsHtmlFromRichTextColumn(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Notes', 'handle' => 'notes', 'type' => 'richtext'],
        ];

        $rows = $this->field->normalizeValue([
            'row1' => [0 => '<p><strong>Bold</strong> note</p>', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $html = $this->field->getPreviewHtml($rows, $this->entry);

        $I->assertSame('Bold note', $html);
    }

    public function getPreviewHtmlEscapesCellText(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
        ];

        $rows = $this->field->normalizeValue([
            'row1' => [0 => 'Fish & Chips', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $html = $this->field->getPreviewHtml($rows, $this->entry);

        $I->assertSame('Fish &amp; Chips', $html);
    }

    public function getPreviewHtmlFallsBackToPluralizedRowCountWithoutATextColumn(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number'],
            1 => ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
        ];

        $oneRow = $this->field->normalizeValue([
            'row1' => [0 => '1', 1 => '1', '_isHeader' => '', '_rowClass' => ''],
        ], null);
        $threeRows = $this->field->normalizeValue([
            'row1' => [0 => '1', 1 => '', '_isHeader' => '', '_rowClass' => ''],
            'row2' => [0 => '2', 1 => '', '_isHeader' => '', '_rowClass' => ''],
            'row3' => [0 => '3', 1 => '', '_isHeader' => '', '_rowClass' => ''],
        ], null);

        $I->assertSame('1 row', $this->field->getPreviewHtml($oneRow, $this->entry));
        $I->assertSame('3 rows', $this->field->getPreviewHtml($threeRows, $this->entry));
    }

    public function previewPlaceholderHtmlReturnsTheFieldsOwnLabel(IntegrationTester $I): void
    {
        $this->field->name = 'Fish & Chips';

        $I->assertSame('Fish &amp; Chips', $this->field->previewPlaceholderHtml(null, null));
    }

    /**
     * Instantiating a bare Entry needs a booted app — its constructor
     * attaches CustomFieldBehavior, a class Craft generates dynamically once
     * Craft::$app exists — so this half of rowsForExport()'s coverage (a real
     * element in a relations cell) belongs here, not the Unit suite.
     */
    public function rowsForExportConvertsRelationElementsToIds(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $target = $this->entry;

        $exported = $this->field->rowsForExport([
            'row1' => [0 => [$target], '_isHeader' => '', '_rowClass' => ''],
        ]);

        $I->assertSame([$target->id], $exported[0]['linked']);
    }

    /**
     * The full loop: rowsForExport() -> rowsToCsv() -> rowsFromCsv() ->
     * rowsFromImport() -> a real element save -> re-reading it back out
     * through normalizeValue() — the same pipeline the export controller
     * action and the console import command each drive one direction of.
     */
    public function exportImportRoundTripsThroughARealElementSave(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];

        // Self-referencing on purpose: this project's fixture only needs one
        // smartTableTest entry, and the field doesn't forbid a row relating
        // to the very entry it's stored on.
        $target = $this->entry;
        $entry = $this->entry;
        $this->syncFieldToEntryLayout($I, $entry);
        $entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Original', 1 => [(string)$target->id], '_isHeader' => '1', '_rowClass' => 'kept'],
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getErrorSummary(true)));

        $rows = $this->field->normalizeValue($entry->getFieldValue(self::HANDLE), $entry);
        $csv = $this->field->rowsToCsv($this->field->rowsForExport($rows));

        $I->assertStringContainsString('Original', $csv);

        $imported = $this->field->rowsFromImport($this->field->rowsFromCsv($csv));
        $entry->setFieldValue(self::HANDLE, $imported);
        $I->assertTrue(Craft::$app->getElements()->saveElement($entry), implode(' ', $entry->getErrorSummary(true)));

        // rowsFromImport() produces a plain sequential array (row keys are an
        // opaque identifier as far as the field is concerned — 'row1' is only
        // a CP-grid JS convention for a freshly added row, not a format
        // anything here requires), so the imported row is now at key 0, not
        // 'row1'.
        $roundTripped = $entry->getFieldValue(self::HANDLE);
        $I->assertSame('Original', $roundTripped[0]['label']);
        $I->assertTrue($roundTripped[0]['isHeader']);
        $I->assertSame('kept', $roundTripped[0]['rowClass']);
        $I->assertSame($target->id, $roundTripped[0]['linked'][0]->id);
    }

    /**
     * Exercises the shipped `smart-table/_macros.twig` table() macro end to
     * end: real normalizeValue() output (a header row, a data row, a
     * checkbox, a richtext cell, a relations cell) rendered through Craft's
     * actual view layer, via the plugin's registered site template root — not
     * just a string-matching test against the .twig source. Catches both
     * Twig syntax errors in the macro and semantic mistakes (wrong scope
     * attribute, dropped rowClass) that a unit test on PHP alone couldn't
     * see.
     */
    public function accessibleTableMacroRendersSemanticMarkup(IntegrationTester $I): void
    {
        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Body', 'handle' => 'body', 'type' => 'richtext'],
            2 => ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
            3 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];

        $target = $this->entry;

        $rows = $this->field->normalizeValue([
            'row1' => [
                0 => 'Section heading', 1 => '', 2 => '', 3 => [],
                '_isHeader' => '1', '_rowClass' => 'section-row',
            ],
            'row2' => [
                0 => 'A task', 1 => '<p><strong>Bold</strong> note</p>', 2 => '1', 3 => [(string)$target->id],
                '_isHeader' => '', '_rowClass' => '',
            ],
        ], null);

        $html = Craft::$app->getView()->renderString(
            '{% import "smart-table/_macros.twig" as smartTable %}' .
            '{{ smartTable.table(rows, columns, {caption: "Tasks", class: "my-table"}) }}',
            [
                'rows' => $rows,
                'columns' => [
                    ['handle' => 'label', 'heading' => 'Label'],
                    ['handle' => 'body', 'heading' => 'Body', 'raw' => true],
                    ['handle' => 'active', 'heading' => 'Active'],
                    ['handle' => 'linked', 'heading' => 'Linked'],
                ],
            ],
        );

        $I->assertStringContainsString('<table class="smarttable-table my-table">', $html);
        $I->assertStringContainsString('<caption class="smarttable-table__caption">Tasks</caption>', $html);
        $I->assertStringContainsString('<th scope="col" class="smarttable-table__heading">Label</th>', $html);

        // Header row: cells render as <th scope="row">, its BEM row/cell
        // classes carry the --header modifier, and its custom rowClass is
        // appended alongside them rather than replacing them.
        $I->assertStringContainsString('<tr class="smarttable-table__row smarttable-table__row--header section-row">', $html);
        $I->assertStringContainsString('<th class="smarttable-table__cell smarttable-table__cell--header" scope="row">Section heading</th>', $html);

        // Data row: richtext cell is unescaped (raw: true), checkbox cell
        // reads "Yes", relation cell names the related element.
        $I->assertStringContainsString('<strong>Bold</strong>', $html);
        $I->assertStringNotContainsString('&lt;strong&gt;', $html);
        $I->assertStringContainsString('>Yes<', $html);
        if ($target->url !== null) {
            $I->assertStringContainsString('<a href="' . $target->url . '">' . $target->title . '</a>', $html);
        } else {
            $I->assertStringContainsString((string)$target->title, $html);
        }
    }

    /**
     * One test for every column type's GQL mapping, not one test per type:
     * craft\services\Gql::prepareFieldDefinitions() caches its return value by
     * type name alone, completely independent of GqlEntityRegistry —
     * flushing the registry (which this test still does, since it's a
     * separate cache covering the *type object* itself) creates a fresh
     * SmartTableRowType, but the *field definitions* for
     * 'smartTableTest_SmartTableRow' still come back from whichever test's
     * column set got cached there first. There's no supported way to reset
     * that cache, so every column type this test cares about has to be
     * asserted from one getContentGqlType() call.
     */
    public function getContentGqlTypeGeneratesQueryableSchema(IntegrationTester $I): void
    {
        GqlEntityRegistry::flush();

        $this->field->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
            2 => ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
            3 => ['heading' => 'Qty', 'handle' => 'qty', 'type' => 'number'],
            4 => ['heading' => 'When', 'handle' => 'when', 'type' => 'date'],
        ];

        $type = $this->field->getContentGqlType();

        $I->assertInstanceOf(ListOfType::class, $type);
        $fields = $type->getWrappedType()->getFields();

        $I->assertArrayHasKey('isHeader', $fields);
        $I->assertArrayHasKey('rowClass', $fields);
        $I->assertArrayHasKey('label', $fields);
        $I->assertArrayHasKey('linked', $fields);
        $I->assertSame('String', (string)$fields['label']->getType());
        $I->assertSame('Boolean', (string)$fields['active']->getType());
        $I->assertSame('Float', (string)$fields['qty']->getType());
        $I->assertSame('DateTime', (string)$fields['when']->getType());
    }

    /**
     * Proves SmartTableRowType::filterByGqlSchemaScope() actually
     * discriminates by scope, not just "filters everything" or "filters
     * nothing": one entry is in the active schema's scope (smartTableTest,
     * the section its own component string names), the other isn't (pages).
     * Tested via reflection on the private method directly rather than
     * executing a full GraphQL query — the same "test the field's own logic,
     * trust Craft's GQL execution plumbing" approach
     * getContentGqlTypeGeneratesQueryableSchema above already takes.
     *
     * The active schema is service-level state, not part of the per-test DB
     * transaction, so it must be reset by hand or it would leak into every
     * test that runs after this one in the same process.
     */
    public function gqlResolutionFiltersRelationsOutsideSchemaScope(IntegrationTester $I): void
    {
        $inScope = $this->entry;
        $outOfScope = $this->pagesEntry;

        $section = $inScope->getSection();
        $I->assertNotNull($section);

        $gql = Craft::$app->getGql();
        $gql->setActiveSchema(new GqlSchema([
            'scope' => ['sections.' . $section->uid . ':read'],
        ]));

        try {
            $method = new \ReflectionMethod(SmartTableRowType::class, 'filterByGqlSchemaScope');
            $filtered = $method->invoke(null, [$inScope, $outOfScope]);
        } finally {
            $gql->setActiveSchema(null);
        }

        $I->assertSame([$inScope], $filtered);
    }

    /**
     * The mutation-side counterpart to getContentGqlTypeGeneratesQueryableSchema
     * (that one only checks the generated type's shape; this one actually
     * executes a real GraphQL mutation and confirms it saves correctly) — and
     * to saveAndReloadRoundTripsThroughRealElementSave below, but through
     * Craft's GraphQL mutation resolver instead of calling
     * setFieldValue()/saveElement() directly. isHeader/rowClass specifically
     * matter here: a mutation's row can only ever be handle-keyed (GraphQL
     * field names can't start with a digit, so there's no numeric-colId
     * equivalent), which is exactly the input shape that needed
     * normalizeValue() and serializeValue() both fixed to handle — this is
     * the one test that would have caught it if either fix were missing or
     * wrong, rather than trusting the type-shape check alone.
     *
     * The mutation name (`save_smartTableTest_smartTableTest_Entry`) follows
     * from createSmartTableSection() giving the section AND its entry type
     * the same handle — confirmed against a real dev project directly via a
     * `__schema { mutationType { fields { name } } }` introspection query
     * against a full-access schema, the same technique used elsewhere in
     * this project to find a GQL type's real generated name rather than
     * guessing it.
     */
    public function graphQlMutationSavesRowsIncludingIsHeaderAndRowClass(IntegrationTester $I): void
    {
        // GqlEntityRegistry::flush() alone (enough for the read-side test
        // above, which only builds one isolated content type) isn't enough
        // here — this builds a *whole schema* via executeQuery(), and
        // Craft's own Gql service caches that schema build independently of
        // the entity registry. Running after another test that already
        // built a schema in this same process throws "Schema must contain
        // unique named types but contains multiple types named
        // EntryInterface" without this.
        Craft::$app->getGql()->flushCaches();

        $entry = $this->entry;
        $layoutField = $I->fieldOnLayout($entry, self::HANDLE);
        $layoutField->columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
            2 => ['heading' => 'Active', 'handle' => 'active', 'type' => 'checkbox'],
        ];

        $target = $this->newsEntry;

        $section = $entry->getSection();
        $I->assertNotNull($section);

        $gql = Craft::$app->getGql();
        $schema = new GqlSchema([
            'scope' => ['sections.' . $section->uid . ':save'],
        ]);
        $gql->setActiveSchema($schema);

        $query = <<<'GQL'
            mutation SaveEntry($id: ID, $linkedId: [ID]) {
              save_smartTableTest_smartTableTest_Entry(
                id: $id
                smartTableTest: [
                  { label: "Via GraphQL", isHeader: true, rowClass: "gql-row", linked: $linkedId, active: true }
                ]
              ) {
                id
              }
            }
            GQL;

        try {
            $result = $gql->executeQuery($schema, $query, [
                'id' => (string)$entry->id,
                'linkedId' => [(string)$target->id],
            ]);
        } finally {
            $gql->setActiveSchema(null);
        }

        $I->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));

        $reloaded = Entry::find()->id($entry->id)->status(null)->one();
        $I->assertNotNull($reloaded);
        $rows = $reloaded->getFieldValue(self::HANDLE);

        $I->assertCount(1, $rows);
        $row = array_values($rows)[0];
        $I->assertSame('Via GraphQL', $row['label']);
        $I->assertTrue($row['isHeader']);
        $I->assertSame('gql-row', $row['rowClass']);
        $I->assertSame($target->id, $row['linked'][0]->id);
    }

    public function saveAndReloadRoundTripsThroughRealElementSave(IntegrationTester $I): void
    {
        $entry = $this->entry;
        $target = $this->newsEntry;

        $columns = [
            0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline'],
            1 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $layoutField = $I->fieldOnLayout($entry, self::HANDLE);
        $layoutField->columns = $columns;
        $layoutField->minRows = null;
        $layoutField->maxRows = null;

        $entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Hello', 1 => [(string)$target->id], '_isHeader' => '', '_rowClass' => 'my-class'],
        ]);

        $success = Craft::$app->getElements()->saveElement($entry);
        $I->assertTrue($success, implode(' ', $entry->getErrorSummary(true)));

        $reloaded = Entry::find()->id($entry->id)->status(null)->one();
        $I->assertNotNull($reloaded);

        // A freshly-queried Entry gets its own fresh field-layout clone —
        // apply the same override before reading its field value back.
        $I->fieldOnLayout($reloaded, self::HANDLE)->columns = $columns;

        $rows = $reloaded->getFieldValue(self::HANDLE);
        $I->assertSame('Hello', $rows['row1']['label']);
        $I->assertSame('my-class', $rows['row1']['_rowClass']);
        $I->assertInstanceOf(Entry::class, $rows['row1']['linked'][0]);
        $I->assertSame($target->id, $rows['row1']['linked'][0]->id);
    }

    /**
     * Scoped by sourceSiteId, not just fieldId+sourceId: SmartTableField::
     * afterElementSave() writes one relations row per site saveElement()
     * propagates to (see that method's own docblock on why), so on a
     * multi-site project an unscoped query would see up to as many rows as
     * there are sites, not the one this single-site save actually wrote —
     * this query is scoped exactly the way a real read of it would need to
     * be, regardless of how many sites the project running it has.
     *
     * @return array<int, array{sourceId: int, targetId: int, sortOrder: int|null}>
     */
    private function relationsTableRows(SmartTableField $field, Entry $entry): array
    {
        /** @var array<int, array{sourceId: int, targetId: int, sortOrder: int|null}> */
        return (new Query())
            ->select(['sourceId', 'targetId', 'sortOrder'])
            ->from([DbTable::RELATIONS])
            ->where(['fieldId' => $field->id, 'sourceId' => $entry->id, 'sourceSiteId' => $entry->siteId])
            ->all();
    }

    public function saveWritesRelationsToRelationsTable(IntegrationTester $I): void
    {
        $entry = $this->entry;
        $target = $this->newsEntry;

        $layoutField = $I->fieldOnLayout($entry, self::HANDLE);
        $layoutField->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $layoutField->minRows = null;
        $layoutField->maxRows = null;

        $entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => [(string)$target->id], '_isHeader' => '', '_rowClass' => ''],
        ]);

        $I->assertTrue(
            Craft::$app->getElements()->saveElement($entry),
            implode(' ', $entry->getErrorSummary(true)),
        );

        $rows = $this->relationsTableRows($layoutField, $entry);
        $I->assertCount(1, $rows);
        $I->assertSame($target->id, $rows[0]['targetId']);
    }

    public function removingARelationDeletesItsRelationsTableRow(IntegrationTester $I): void
    {
        $entry = $this->entry;
        $target = $this->newsEntry;

        $layoutField = $I->fieldOnLayout($entry, self::HANDLE);
        $layoutField->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $layoutField->minRows = null;
        $layoutField->maxRows = null;

        $entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => [(string)$target->id], '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($entry));
        $I->assertCount(1, $this->relationsTableRows($layoutField, $entry));

        $I->fieldOnLayout($entry, self::HANDLE)->columns = $layoutField->columns;
        $entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => [], '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $I->assertSame([], $this->relationsTableRows($layoutField, $entry));
    }

    public function relatedToFindsSourceElementViaSmartTableRelation(IntegrationTester $I): void
    {
        $entry = $this->entry;
        $target = $this->newsEntry;

        $layoutField = $I->fieldOnLayout($entry, self::HANDLE);
        $layoutField->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $layoutField->minRows = null;
        $layoutField->maxRows = null;

        $entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => [(string)$target->id], '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(Craft::$app->getElements()->saveElement($entry));

        $found = Entry::find()->relatedTo($target)->id($entry->id)->status(null)->one();
        $I->assertNotNull($found, 'Expected relatedTo() to find the source entry via the relations table.');
    }

    /**
     * @return int number of yii\db\Command::query / ::execute log entries
     * recorded while $fn ran — the same signal the debug toolbar's DB panel
     * reads, not timing (which would be flaky).
     */
    private function countQueries(callable $fn): int
    {
        $logger = \Yii::getLogger();
        $logger->messages = [];
        $fn();

        $count = 0;
        foreach ($logger->messages as $message) {
            $category = is_array($message) ? ($message[2] ?? '') : '';
            if (str_contains((string)$category, 'yii\db\Command::query') || str_contains((string)$category, 'yii\db\Command::execute')) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Two relations columns of the same element type should share one query
     * for the whole field, not issue one each — the point of grouping
     * normalizeValue()'s lookups by element class rather than by column.
     * Matching specific queries by SQL text would be brittle across Craft
     * versions, so this compares total query count instead: one column
     * referencing 2 IDs vs. two columns referencing the same 2 IDs between
     * them should cost the same number of queries. If the columns weren't
     * merged, the two-column case would cost more.
     */
    public function twoColumnsOfSameRelationTypeShareOneQuery(IntegrationTester $I): void
    {
        $entryA = $this->entry;
        $entryB = $this->newsEntry;

        $this->field->columns = [
            0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries'],
        ];
        $oneColumnQueries = $this->countQueries(function() use ($I, $entryA, $entryB) {
            $rows = $this->field->normalizeValue([
                'row1' => [0 => [(string)$entryA->id, (string)$entryB->id], '_isHeader' => '', '_rowClass' => ''],
            ], null);
            $I->assertCount(2, $rows['row1']['linked']);
        });

        $this->field->columns = [
            0 => ['heading' => 'First', 'handle' => 'first', 'type' => 'relations_entries'],
            1 => ['heading' => 'Second', 'handle' => 'second', 'type' => 'relations_entries'],
        ];
        $rows = null;
        $twoColumnQueries = $this->countQueries(function() use ($entryA, $entryB, &$rows) {
            $rows = $this->field->normalizeValue([
                'row1' => [0 => [(string)$entryA->id], 1 => [(string)$entryB->id], '_isHeader' => '', '_rowClass' => ''],
            ], null);
        });
        $I->assertIsArray($rows);

        $I->assertSame(
            $oneColumnQueries,
            $twoColumnQueries,
            'Expected two same-type relations columns to cost the same query count as one column referencing the same total IDs.',
        );
        $I->assertSame($entryA->id, $rows['row1']['first'][0]->id);
        $I->assertSame($entryB->id, $rows['row1']['second'][0]->id);
    }
}
