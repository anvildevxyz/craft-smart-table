<?php

namespace Helper;

use anvildevxyz\smarttable\fields\SmartTableField;
use Codeception\Module;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use DateTime;

/**
 * Integration-suite helper: fixture actions every Cest builds on.
 *
 * A section/field/entry-type built here is cached in-process by Craft's own
 * services (Fields, Entries) the moment it's saved — the per-test DB
 * transaction (see codeception.yml's `transaction: true`) rolls back the
 * underlying rows between tests, but the in-memory service caches don't know
 * that, so a later test's "does this handle already exist?" check still says
 * yes. That's deliberate, not a bug to work around: every create*() method
 * here is idempotent specifically so the *first* test that needs a given
 * fixture creates it once for the whole suite run, and every test after that
 * reuses the same cached section/field/entry-type instances — matching the
 * same pattern craft-seo's own Helper uses.
 */
class Integration extends Module
{
    /**
     * Creates (idempotently) a channel section + entry type carrying a
     * SmartTableField with the given handle — both the section AND its
     * entry type share $handle, so a GraphQL mutation test can predict the
     * generated `save_{sectionHandle}_{entryTypeHandle}_Entry` mutation name
     * without the helper picking a different entry-type handle underneath it.
     *
     * Returns the field via the entry type's own field layout, not
     * `Craft::$app->getFields()->getFieldByHandle()` — `CustomField::setField()`
     * clones the field it's given (a defensive clone: a layout element carries
     * its own name/handle/instructions overrides), so `saveElement()` on an
     * entry in this section normalizes/serializes through the *layout's*
     * field instance, not the Fields-service singleton. A test that mutates
     * `$fixture['field']->columns` and then saves a real entry needs this to
     * actually take effect.
     *
     * @return array{section: Section, entryType: EntryType, field: SmartTableField}
     */
    public function createSmartTableSection(string $handle): array
    {
        $entries = \Craft::$app->getEntries();

        $existingSection = $entries->getSectionByHandle($handle);
        if ($existingSection !== null) {
            $entryType = $existingSection->getEntryTypes()[0];
            $field = $entryType->getFieldLayout()?->getFieldByHandle($handle);
            $this->assertInstanceOf(SmartTableField::class, $field);
            return ['section' => $existingSection, 'entryType' => $entryType, 'field' => $field];
        }

        $field = new SmartTableField();
        $field->name = 'Smart Table';
        $field->handle = $handle;
        $fields = \Craft::$app->getFields();
        $this->assertTrue($fields->saveField($field), 'save field: ' . json_encode($field->getErrors()));

        $entryType = new EntryType();
        $entryType->name = ucfirst($handle);
        $entryType->handle = $handle;
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        // Without an explicit EntryTitleField element, Craft 5 treats the type
        // as title-less and NULLS the title on save.
        $layout->setTabs([
            ['name' => 'Content', 'elements' => [
                ['type' => EntryTitleField::class],
                ['type' => CustomField::class, 'fieldUid' => $field->uid],
            ]],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue(
            $entries->saveEntryType($entryType),
            'save entry type: ' . json_encode($entryType->getErrors()),
        );

        $site = \Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = ucfirst($handle);
        $section->handle = $handle;
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings(['siteId' => $site->id, 'enabledByDefault' => true, 'hasUrls' => false]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(
            $entries->saveSection($section),
            'save section: ' . json_encode($section->getErrors()),
        );

        $field = $entryType->getFieldLayout()?->getFieldByHandle($handle);
        $this->assertInstanceOf(SmartTableField::class, $field);

        return ['section' => $section, 'entryType' => $entryType, 'field' => $field];
    }

    /**
     * Creates (idempotently) a second site in the same site group as the
     * suite's primary site — needed by the multi-site Cests, which are the
     * only tests in this suite that need more than one site to exist at
     * all. Every other fixture here stays deliberately single-site so it
     * doesn't have to reason about propagation.
     *
     * Craft::$app->getIsMultiSite() memoizes its result for the lifetime of
     * the application instance, in two entirely separate properties keyed by
     * its `$withTrashed` argument (`ApplicationTrait::getIsMultiSite()`):
     * `$withTrashed = true` reads/writes ONLY `_isMultiSiteWithTrashed`, and
     * `$withTrashed = false` (the default) reads/writes ONLY `_isMultiSite` —
     * passing `$refresh = true` with one variant never touches the other's
     * memo. Both are load-bearing here: ElementQuery::prepare() only adds its
     * `elements_sites.siteId = ...` WHERE condition when
     * `getIsMultiSite(false, true)` (the `$withTrashed` variant) is true, and
     * Elements::_saveElementInternal()'s `$propagate` decision — which
     * controls whether a newly created entry propagates to this second site
     * at all — gates on the PLAIN variant. Since this whole suite boots ONE
     * Craft app for its entire run and the single-site Cests query that app
     * before this method ever runs, both memos are already cached `false` by
     * the time a multi-site Cest calls this, and busting only one leaves the
     * other's stale `false` silently breaking either site-scoped queries or
     * cross-site propagation depending on which one was missed. Confirmed via
     * a raw SQL dump (ElementQuery::createCommand()->getRawSql()) showing no
     * siteId condition at all in the generated query when only the plain
     * variant had been refreshed. Both calls below are required — this is a
     * test-harness artifact of the memo outliving the site count that
     * produced it, not a behavior real Craft requests (which re-derive it
     * fresh per request) or the plugin itself exhibit.
     */
    public function createSecondSite(): Site
    {
        $sites = \Craft::$app->getSites();

        $existing = $sites->getSiteByHandle('secondSite');
        if ($existing !== null) {
            \Craft::$app->getIsMultiSite(true, false);
            \Craft::$app->getIsMultiSite(true, true);
            return $existing;
        }

        $primary = $sites->getPrimarySite();
        $site = new Site([
            'groupId' => $primary->groupId,
            'name' => 'Second Site',
            'handle' => 'secondSite',
            'language' => 'en-US',
            'hasUrls' => true,
            'baseUrl' => 'https://second-site.test/',
        ]);
        $this->assertTrue($sites->saveSite($site), 'save site: ' . json_encode($site->getErrors()));
        \Craft::$app->getIsMultiSite(true, false);
        \Craft::$app->getIsMultiSite(true, true);

        return $site;
    }

    /**
     * Creates (idempotently) a channel section + entry type carrying a
     * SmartTableField, enabled for BOTH the primary site and
     * createSecondSite()'s site — a dedicated fixture kept separate from
     * createSmartTableSection()'s single-site one so the multi-site Cests
     * can't accidentally affect the single-site suite's assumptions (e.g.
     * that every entry it creates only ever has one site row).
     *
     * Section::$propagationMethod defaults to PropagationMethod::All, so a
     * plain saveElement() on an entry in this section already propagates to
     * both sites without any extra fixture wiring.
     *
     * @return array{section: Section, entryType: EntryType, field: SmartTableField}
     */
    public function createMultiSiteSmartTableSection(string $handle): array
    {
        $entries = \Craft::$app->getEntries();

        $existingSection = $entries->getSectionByHandle($handle);
        if ($existingSection !== null) {
            $entryType = $existingSection->getEntryTypes()[0];
            $field = $entryType->getFieldLayout()?->getFieldByHandle($handle);
            $this->assertInstanceOf(SmartTableField::class, $field);
            return ['section' => $existingSection, 'entryType' => $entryType, 'field' => $field];
        }

        $secondSite = $this->createSecondSite();

        $field = new SmartTableField();
        $field->name = 'Smart Table';
        $field->handle = $handle;
        $fields = \Craft::$app->getFields();
        $this->assertTrue($fields->saveField($field), 'save field: ' . json_encode($field->getErrors()));

        $entryType = new EntryType();
        $entryType->name = ucfirst($handle);
        $entryType->handle = $handle;
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            ['name' => 'Content', 'elements' => [
                ['type' => EntryTitleField::class],
                ['type' => CustomField::class, 'fieldUid' => $field->uid],
            ]],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue(
            $entries->saveEntryType($entryType),
            'save entry type: ' . json_encode($entryType->getErrors()),
        );

        $primarySite = \Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = ucfirst($handle);
        $section->handle = $handle;
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings(['siteId' => $primarySite->id, 'enabledByDefault' => true, 'hasUrls' => false]),
            new Section_SiteSettings(['siteId' => $secondSite->id, 'enabledByDefault' => true, 'hasUrls' => false]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(
            $entries->saveSection($section),
            'save section: ' . json_encode($section->getErrors()),
        );

        $field = $entryType->getFieldLayout()?->getFieldByHandle($handle);
        $this->assertInstanceOf(SmartTableField::class, $field);

        return ['section' => $section, 'entryType' => $entryType, 'field' => $field];
    }

    /**
     * Creates (idempotently) a plain channel section + entry type with no
     * SmartTableField at all — used as a source of "an entry from a
     * different, out-of-scope section" for relation-source-restriction and
     * GraphQL-schema-scope tests.
     *
     * @return array{section: Section, entryType: EntryType}
     */
    public function createPlainSection(string $handle): array
    {
        $entries = \Craft::$app->getEntries();

        $existingSection = $entries->getSectionByHandle($handle);
        if ($existingSection !== null) {
            return ['section' => $existingSection, 'entryType' => $existingSection->getEntryTypes()[0]];
        }

        $entryType = new EntryType();
        $entryType->name = ucfirst($handle);
        $entryType->handle = $handle;
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            ['name' => 'Content', 'elements' => [['type' => EntryTitleField::class]]],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue(
            $entries->saveEntryType($entryType),
            'save entry type: ' . json_encode($entryType->getErrors()),
        );

        $site = \Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = ucfirst($handle);
        $section->handle = $handle;
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings(['siteId' => $site->id, 'enabledByDefault' => true, 'hasUrls' => false]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(
            $entries->saveSection($section),
            'save section: ' . json_encode($section->getErrors()),
        );

        return ['section' => $section, 'entryType' => $entryType];
    }

    /**
     * Saves one live entry in a fixture section (as returned by
     * createSmartTableSection()/createPlainSection()).
     *
     * @param array{section: Section, entryType: EntryType} $fixture
     */
    public function createEntry(array $fixture, string $title): Entry
    {
        $entry = new Entry();
        $entry->sectionId = $fixture['section']->id;
        $entry->typeId = $fixture['entryType']->id;
        $entry->title = $title;
        $entry->postDate = new DateTime('-1 hour');
        $this->assertTrue(
            \Craft::$app->getElements()->saveElement($entry),
            'save entry: ' . json_encode($entry->getErrors()),
        );

        return $entry;
    }

    /**
     * The SmartTableField instance a real entry's own field layout resolves
     * to for the given handle — see createSmartTableSection()'s docblock for
     * why this is a different object than the Fields-service singleton, and
     * why a save-path test needs to mutate settings on *this* one.
     */
    public function fieldOnLayout(Entry $entry, string $handle): SmartTableField
    {
        $field = $entry->getFieldLayout()?->getFieldByHandle($handle);
        $this->assertInstanceOf(SmartTableField::class, $field);
        return $field;
    }

    /**
     * Creates (idempotently) a Matrix field whose only nested entry type
     * carries a SmartTableField in its own field layout — a Matrix "block
     * type" in Craft 5 is a real EntryType with a real FieldLayout, so
     * nesting the field this way exercises the exact same
     * normalizeValue()/serializeValue()/afterElementSave() path a top-level
     * field goes through, just on a nested Entry element instead of the
     * page's own entry.
     *
     * Like createSmartTableSection()'s returned field, callers change column
     * settings by mutating the returned object's `columns` property directly
     * (see SmartTableFieldMatrixNestingCest) rather than calling
     * `saveField()` again — the Entries service caches EntryType (and so its
     * FieldLayout, and so this field clone) for the life of the test
     * process, so a *new* nested entry built moments later from Matrix's
     * array-form `setFieldValue()` payload resolves its field settings
     * through this exact same object.
     *
     * @return array{matrixField: Matrix, blockType: EntryType, smartTableField: SmartTableField}
     */
    public function createMatrixWithSmartTable(string $matrixHandle, string $blockTypeHandle, string $smartTableHandle): array
    {
        $fields = \Craft::$app->getFields();
        $entries = \Craft::$app->getEntries();

        $existingMatrixField = $fields->getFieldByHandle($matrixHandle);
        if ($existingMatrixField instanceof Matrix) {
            $blockType = $existingMatrixField->getEntryTypes()[0];
            $smartTableField = $blockType->getFieldLayout()?->getFieldByHandle($smartTableHandle);
            $this->assertInstanceOf(SmartTableField::class, $smartTableField);
            return ['matrixField' => $existingMatrixField, 'blockType' => $blockType, 'smartTableField' => $smartTableField];
        }

        $smartTableField = new SmartTableField();
        $smartTableField->name = 'Nested Smart Table';
        $smartTableField->handle = $smartTableHandle;
        $this->assertTrue(
            $fields->saveField($smartTableField),
            'save nested field: ' . json_encode($smartTableField->getErrors()),
        );

        $blockType = new EntryType();
        $blockType->name = ucfirst($blockTypeHandle);
        $blockType->handle = $blockTypeHandle;
        // Matrix blocks don't need a title of their own — keeping this off
        // avoids having to feed a title through every nested-entry payload
        // this Cest builds.
        $blockType->hasTitleField = false;
        $blockType->showSlugField = false;
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            ['name' => 'Content', 'elements' => [
                ['type' => CustomField::class, 'fieldUid' => $smartTableField->uid],
            ]],
        ]);
        $blockType->setFieldLayout($layout);
        $this->assertTrue(
            $entries->saveEntryType($blockType),
            'save block type: ' . json_encode($blockType->getErrors()),
        );

        $matrixField = new Matrix();
        $matrixField->name = 'Nested Matrix';
        $matrixField->handle = $matrixHandle;
        $matrixField->setEntryTypes([$blockType]);
        $this->assertTrue(
            $fields->saveField($matrixField),
            'save matrix field: ' . json_encode($matrixField->getErrors()),
        );

        $blockType = $matrixField->getEntryTypes()[0];
        $smartTableField = $blockType->getFieldLayout()?->getFieldByHandle($smartTableHandle);
        $this->assertInstanceOf(SmartTableField::class, $smartTableField);

        return ['matrixField' => $matrixField, 'blockType' => $blockType, 'smartTableField' => $smartTableField];
    }

    /**
     * Creates (idempotently, by handle) a real craft\ckeditor\Field — used
     * to exercise SmartTableField's Rich Text Toolbar setting (which reuses
     * an existing native CKEditor field's toolbar) against an actual field,
     * not a mock.
     */
    public function createCkeditorField(string $handle, array $toolbar): CkeditorField
    {
        $fields = \Craft::$app->getFields();

        $existing = $fields->getFieldByHandle($handle);
        if ($existing instanceof CkeditorField) {
            return $existing;
        }

        $field = new CkeditorField();
        $field->name = ucfirst($handle);
        $field->handle = $handle;
        $field->toolbar = $toolbar;
        $this->assertTrue($fields->saveField($field), 'save CkeditorField: ' . json_encode($field->getErrors()));

        return $field;
    }

    /**
     * Creates (idempotently) a channel section + entry type whose field
     * layout carries the given field (plus a title) — used to host a Matrix
     * field for the nesting Cest, distinct from createSmartTableSection()
     * because that one hardcodes a SmartTableField directly on the section
     * rather than an arbitrary field passed in by the caller.
     *
     * @return array{section: Section, entryType: EntryType}
     */
    public function createSectionWithField(string $sectionHandle, FieldInterface $field): array
    {
        $entries = \Craft::$app->getEntries();

        $existingSection = $entries->getSectionByHandle($sectionHandle);
        if ($existingSection !== null) {
            return ['section' => $existingSection, 'entryType' => $existingSection->getEntryTypes()[0]];
        }

        $entryType = new EntryType();
        $entryType->name = ucfirst($sectionHandle);
        $entryType->handle = $sectionHandle;
        $layout = new FieldLayout();
        $layout->type = Entry::class;
        $layout->setTabs([
            ['name' => 'Content', 'elements' => [
                ['type' => EntryTitleField::class],
                ['type' => CustomField::class, 'fieldUid' => $field->uid],
            ]],
        ]);
        $entryType->setFieldLayout($layout);
        $this->assertTrue(
            $entries->saveEntryType($entryType),
            'save entry type: ' . json_encode($entryType->getErrors()),
        );

        $site = \Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = ucfirst($sectionHandle);
        $section->handle = $sectionHandle;
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            new Section_SiteSettings(['siteId' => $site->id, 'enabledByDefault' => true, 'hasUrls' => false]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(
            $entries->saveSection($section),
            'save section: ' . json_encode($section->getErrors()),
        );

        return ['section' => $section, 'entryType' => $entryType];
    }
}
