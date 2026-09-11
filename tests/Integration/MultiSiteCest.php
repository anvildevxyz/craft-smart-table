<?php

namespace anvildevxyz\smarttable\tests\integration;

use anvildevxyz\smarttable\fields\SmartTableField;
use Craft;
use craft\base\Field;
use craft\db\Query;
use craft\db\Table as DbTable;
use craft\elements\Entry;
use craft\models\Site;
use IntegrationTester;

/**
 * Exercises SmartTableField against a real two-site Craft fixture — the gap
 * the README's Status section used to flag as "not tested". Covers the three
 * scenarios from issue #16:
 *
 * 1. With the field's default translation method (none), a save on one site
 *    is visible identically when the same element is reloaded on another
 *    site — proving Craft's own "shared, not per-site" content propagation
 *    actually behaves that way for this field, not just in theory.
 * 2. With the field's translation method set to per-site (`site`), two sites
 *    of the same element can hold genuinely different row data without one
 *    save clobbering the other.
 * 3. A relations-type column's picks, saved on one site, are scoped to that
 *    site's `sourceSiteId` in the `relations` table (see
 *    SmartTableField::afterElementSave()'s own docblock) and don't leak into
 *    relatedTo() queries run against a different site.
 *
 * Uses its own dedicated section/field fixture (`smartTableMultiSite`,
 * enabled on both the suite's primary site and Helper\Integration::
 * createSecondSite()'s site) rather than SmartTableFieldCest's single-site
 * one, so nothing here can affect that suite's single-site assumptions.
 */
class MultiSiteCest
{
    private const HANDLE = 'smartTableMultiSite';

    private SmartTableField $field;
    private Entry $entry;
    private Site $secondSite;

    public function _before(IntegrationTester $I): void
    {
        $fixture = $I->createMultiSiteSmartTableSection(self::HANDLE);
        $this->secondSite = $I->createSecondSite();
        $this->field = $fixture['field'];
        // Created via the plain new Entry()/saveElement() path, which
        // defaults to the primary site (see craft\base\Element::init()) and,
        // per Section::$propagationMethod's PropagationMethod::All default,
        // immediately propagates a blank field value to $this->secondSite
        // too.
        $this->entry = $I->createEntry($fixture, 'Multi Site Entry');

        // Same rationale as SmartTableFieldCest::_before(): $this->field is a
        // process-lifetime-cached instance reused by every test in this
        // suite, so reset every setting a test might mutate.
        $this->field->columns = [0 => ['heading' => '', 'handle' => '', 'type' => 'singleline']];
        $this->field->minRows = null;
        $this->field->maxRows = null;
        $this->field->name = 'Smart Table';
        $this->field->translationMethod = Field::TRANSLATION_METHOD_NONE;
        $this->field->clearErrors();
    }

    /**
     * Confirms the field really does inherit Craft's TRANSLATION_METHOD_NONE
     * default (no override anywhere in SmartTableField), and that this
     * translates into the behavior the name implies: a value saved while
     * editing the primary site is visible, unchanged, when the very same
     * element is reloaded on a completely different site.
     */
    public function defaultTranslationMethodIsNoneAndSharesValueAcrossSites(IntegrationTester $I): void
    {
        $I->assertSame(Field::TRANSLATION_METHOD_NONE, $this->field->translationMethod);

        $columns = [0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline']];
        $layoutField = $I->fieldOnLayout($this->entry, self::HANDLE);
        $layoutField->columns = $columns;

        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Shared value', '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($this->entry),
            implode(' ', $this->entry->getErrorSummary(true)),
        );

        $onSecondSite = Entry::find()->id($this->entry->id)->siteId($this->secondSite->id)->status(null)->one();
        $I->assertNotNull($onSecondSite, 'Expected the entry to already have a row on the second site.');
        $I->fieldOnLayout($onSecondSite, self::HANDLE)->columns = $columns;

        $rows = $onSecondSite->getFieldValue(self::HANDLE);
        $I->assertSame('Shared value', $rows['row1']['label']);
    }

    /**
     * With the translation method switched to per-site, saving the primary
     * site's element must NOT overwrite the second site's independently
     * saved value, and vice versa — the actual "two sites, two truly
     * different values" case #16 asks for, not just two saves that happen to
     * agree because nothing diverged yet.
     */
    public function perSiteTranslationMethodKeepsSitesIndependent(IntegrationTester $I): void
    {
        $columns = [0 => ['heading' => 'Label', 'handle' => 'label', 'type' => 'singleline']];
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $primaryField = $I->fieldOnLayout($this->entry, self::HANDLE);
        $primaryField->columns = $columns;
        $primaryField->translationMethod = Field::TRANSLATION_METHOD_SITE;
        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Primary Value', '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($this->entry),
            implode(' ', $this->entry->getErrorSummary(true)),
        );

        $secondSiteEntry = Entry::find()->id($this->entry->id)->siteId($this->secondSite->id)->status(null)->one();
        $I->assertNotNull($secondSiteEntry);
        $secondField = $I->fieldOnLayout($secondSiteEntry, self::HANDLE);
        $secondField->columns = $columns;
        $secondField->translationMethod = Field::TRANSLATION_METHOD_SITE;
        $secondSiteEntry->setFieldValue(self::HANDLE, [
            'row1' => [0 => 'Second Value', '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($secondSiteEntry),
            implode(' ', $secondSiteEntry->getErrorSummary(true)),
        );

        $reloadedPrimary = Entry::find()->id($this->entry->id)->siteId($primarySiteId)->status(null)->one();
        $I->assertNotNull($reloadedPrimary);
        $I->fieldOnLayout($reloadedPrimary, self::HANDLE)->columns = $columns;
        $primaryRows = $reloadedPrimary->getFieldValue(self::HANDLE);
        $I->assertSame('Primary Value', $primaryRows['row1']['label']);

        $reloadedSecond = Entry::find()->id($this->entry->id)->siteId($this->secondSite->id)->status(null)->one();
        $I->assertNotNull($reloadedSecond);
        $I->fieldOnLayout($reloadedSecond, self::HANDLE)->columns = $columns;
        $secondRows = $reloadedSecond->getFieldValue(self::HANDLE);
        $I->assertSame('Second Value', $secondRows['row1']['label']);
    }

    /**
     * @return array<int, array{sourceId: int, sourceSiteId: int, targetId: int}>
     */
    private function relationsTableRowsForSite(SmartTableField $field, Entry $entry, int $siteId): array
    {
        /** @var array<int, array{sourceId: int, sourceSiteId: int, targetId: int}> */
        return (new Query())
            ->select(['sourceId', 'sourceSiteId', 'targetId'])
            ->from([DbTable::RELATIONS])
            ->where(['fieldId' => $field->id, 'sourceId' => $entry->id, 'sourceSiteId' => $siteId])
            ->all();
    }

    /**
     * With the translation method set to per-site (so the two sites'
     * relation picks can genuinely diverge, unlike the shared-value default
     * scenario above), a relations-type column's picks saved only on the
     * primary site must land in the `relations` table scoped to the primary
     * site's sourceSiteId — and relatedTo() run against the second site,
     * which never received that pick, must not see it. Proves
     * afterElementSave()'s sourceSiteId scoping (see that method's own
     * docblock) actually prevents the cross-site leak it claims to prevent,
     * rather than merely intending to.
     */
    public function relationsPicksAreScopedToTheSavingSiteAndDoNotLeak(IntegrationTester $I): void
    {
        $targetFixture = $I->createPlainSection('multiSiteRelTarget');
        $target = $I->createEntry($targetFixture, 'Multi Site Rel Target');

        $columns = [0 => ['heading' => 'Linked', 'handle' => 'linked', 'type' => 'relations_entries']];
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        $primaryField = $I->fieldOnLayout($this->entry, self::HANDLE);
        $primaryField->columns = $columns;
        $primaryField->translationMethod = Field::TRANSLATION_METHOD_SITE;
        $primaryField->minRows = null;
        $primaryField->maxRows = null;
        $this->entry->setFieldValue(self::HANDLE, [
            'row1' => [0 => [(string)$target->id], '_isHeader' => '', '_rowClass' => ''],
        ]);
        $I->assertTrue(
            Craft::$app->getElements()->saveElement($this->entry),
            implode(' ', $this->entry->getErrorSummary(true)),
        );

        $primaryRelations = $this->relationsTableRowsForSite($primaryField, $this->entry, $primarySiteId);
        $I->assertCount(1, $primaryRelations);
        $I->assertSame($target->id, $primaryRelations[0]['targetId']);

        $secondSiteRelations = $this->relationsTableRowsForSite($primaryField, $this->entry, $this->secondSite->id);
        $I->assertSame([], $secondSiteRelations, 'Expected no relations row for the site the pick was never saved on.');

        $foundOnPrimary = Entry::find()
            ->relatedTo($target)
            ->id($this->entry->id)
            ->siteId($primarySiteId)
            ->status(null)
            ->one();
        $I->assertNotNull($foundOnPrimary, 'Expected relatedTo() at the primary site to see the relation saved there.');

        $foundOnSecondSite = Entry::find()
            ->relatedTo($target)
            ->id($this->entry->id)
            ->siteId($this->secondSite->id)
            ->status(null)
            ->one();
        $I->assertNull(
            $foundOnSecondSite,
            'Expected relatedTo() at the second site not to see a relation only ever saved on the primary site.',
        );
    }
}
