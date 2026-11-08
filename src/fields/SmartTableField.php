<?php

namespace anvildevxyz\smarttable\fields;

require_once dirname(__DIR__) . '/compat/InputSchemaFieldInterface.php';

use anvildevxyz\smarttable\assetbundles\SmartTableFieldAsset;
use anvildevxyz\smarttable\gql\SmartTableRowType;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\InputSchemaFieldInterface;
use craft\base\PreviewableFieldInterface;
use craft\ckeditor\Field as CkeditorField;
use craft\db\Table as DbTable;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\BaseRelationField;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\elements\Asset as AssetInterface;
use craft\gql\interfaces\elements\Category as CategoryInterface;
use craft\gql\interfaces\elements\Entry as EntryInterface;
use craft\gql\interfaces\elements\User as UserInterface;
use craft\gql\types\DateTime as GqlDateTime;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\HtmlPurifier;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\CategoryGroup;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use DateTime;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type as GqlType;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\Schema;

/**
 * Every `$columns` entry (see below) shares this same loosely-typed shape —
 * declared once here so every method that receives or returns one references
 * this alias instead of repeating `array<string, mixed>` in its own docblock.
 *
 * @phpstan-type ColumnConfig array<string, mixed>
 */
class SmartTableField extends Field implements InputSchemaFieldInterface, PreviewableFieldInterface
{
    /**
     * Single source for every column type: its settings-dropdown label, and
     * (for relation types) the element class it queries. Kept as one array,
     * not two parallel ones, so a new relations type can't be added to the
     * dropdown while its element-class mapping is forgotten — the type would
     * otherwise silently degrade to a plain text column with no error.
     */
    private const COLUMN_TYPES = [
        'singleline' => ['label' => 'Single-line text'],
        'richtext' => ['label' => 'Rich text'],
        'number' => ['label' => 'Number'],
        'date' => ['label' => 'Date'],
        'checkbox' => ['label' => 'Checkbox'],
        'select' => ['label' => 'Dropdown'],
        'color' => ['label' => 'Color'],
        'email' => ['label' => 'Email'],
        'url' => ['label' => 'URL'],
        'relations_entries' => ['label' => 'Relations (Entries)', 'elementType' => Entry::class],
        'relations_assets' => ['label' => 'Relations (Assets)', 'elementType' => Asset::class],
        'relations_categories' => ['label' => 'Relations (Categories)', 'elementType' => Category::class],
        'relations_users' => ['label' => 'Relations (Users)', 'elementType' => User::class],
    ];

    /**
     * Toolbar buttons this field's minimal `CKEditor5.craftcms.create()` call
     * can render correctly with zero extra `config.*` wiring (no heading
     * levels, font choices, asset-transform lists, or entry-type
     * restrictions to supply). Any button outside this set is stripped from
     * a chosen source field's toolbar before it reaches the editor — see
     * resolvedToolbar().
     */
    private const SUPPORTED_TOOLBAR_ITEMS = [
        'bold',
        'italic',
        'underline',
        'strikethrough',
        'subscript',
        'superscript',
        'removeFormat',
        'link',
        'bulletedList',
        'numberedList',
        'todoList',
        'blockQuote',
        'code',
        'codeBlock',
        'horizontalLine',
    ];

    /**
     * The toolbar Rich text columns used before this setting existed —
     * kept as the fallback for a field with no toolbar source chosen (or
     * one whose chosen source field was since deleted), so upgrading
     * doesn't silently change already-saved fields' toolbars.
     */
    private const DEFAULT_TOOLBAR = ['bold', 'italic', 'link', 'bulletedList'];

    /**
     * Column IDs must stay numeric (not e.g. "col1"): the settings table's
     * generic Craft.EditableTable JS assigns bare numeric IDs to new rows,
     * and mixing numeric with non-numeric keys triggers JS object key
     * reordering (integer-like keys enumerate first), scrambling column order.
     *
     * @var array<int, ColumnConfig>
     */
    public array $columns = [
        0 => ['heading' => '', 'handle' => '', 'type' => 'singleline'],
    ];

    public ?int $minRows = null;
    public ?int $maxRows = null;

    /**
     * The UID of the site's existing craft\ckeditor\Field whose toolbar
     * Rich text columns should reuse. Null falls back to DEFAULT_TOOLBAR —
     * either because no field has been chosen yet, or the chosen one was
     * since deleted.
     */
    public ?string $toolbarSourceFieldUid = null;

    /**
     * $handle is nullable on craft\base\Field (an unsaved/new field has none
     * yet), but every method that calls this only runs once the field is
     * attached to a real element for validation or rendering — by which
     * point it's always set.
     */
    private function handle(): string
    {
        if ($this->handle === null) {
            throw new InvalidConfigException('Field handle is not set.');
        }
        return $this->handle;
    }

    public function __construct(array $config = [])
    {
        // richTextToolbar (a checkbox list) and then ckeConfig (a CkeConfig
        // UID, from a craftcms/ckeditor version that has since dropped that
        // whole shared-config concept) both preceded $toolbarSourceFieldUid.
        // showHeaderColumn preceded the action-menu-only Header row control.
        // Drop them so loading an already-saved field's settings doesn't
        // throw on a now-undeclared property.
        unset($config['richTextToolbar'], $config['ckeConfig'], $config['showHeaderColumn']);

        if (isset($config['columns'])) {
            // Re-sequence to plain 0,1,2,... every time settings are loaded
            // or saved, in current (visual) order. A column reordered or
            // added via the settings grid can end up with a non-sequential
            // or non-numeric key; without this, a row added later via the
            // main grid's "Add a row" (which orders cells by the same
            // numeric-first JS rule) could render columns in a different
            // order than existing rows.
            $config['columns'] = is_array($config['columns']) ? array_values($config['columns']) : [];
        }

        parent::__construct($config);
    }

    public static function displayName(): string
    {
        return Craft::t('smart-table', 'Smart Table');
    }

    public static function icon(): string
    {
        return 'table';
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_JSON;
    }

    /**
     * Generates one GraphQL object type per field instance (named
     * `{handle}_SmartTableRow`), since each field's row shape is defined by
     * its own $columns settings — the same per-field dynamic-type approach
     * craft\fields\Table uses for the same reason. A column needs a handle
     * to appear here: GraphQL field names can't start with a digit, so the
     * numeric colId keys normalizeValue() also indexes by aren't usable as
     * GQL field names, only the handle-keyed ones are.
     *
     * Relations are resolved to PHP element objects in normalizeValue(), not
     * through a lazy ElementQuery, so Craft's GraphQL eager-loading does not
     * apply. Schema scope is enforced at resolve time in SmartTableRowType.
     *
     * @return GqlType|array<string, mixed>
     */
    public function getContentGqlType(): GqlType|array
    {
        $typeName = $this->handle() . '_SmartTableRow';

        /** @var GqlType $rowType */
        $rowType = GqlEntityRegistry::getOrCreate($typeName, function() use ($typeName) {
            $fields = $this->rowGqlFields($this->columnGqlType(...));

            return new SmartTableRowType([
                'name' => $typeName,
                'fields' => fn() => Craft::$app->getGql()->prepareFieldDefinitions($fields, $typeName),
            ]);
        });

        return GqlType::listOf($rowType);
    }

    /**
     * The mutation-side counterpart to getContentGqlType() — a
     * `{handle}_SmartTableRowInput` per field instance, the same
     * per-field-dynamic-type approach craft\fields\Table's own
     * getContentGqlMutationArgumentType() uses for the same reason (each
     * field's row shape depends on its own $columns).
     *
     * Deliberately doesn't need a GQL-layer `normalizeValue` config
     * callback the way craft\fields\Matrix's mutation input does — Matrix's
     * GraphQL input shape doesn't match what its own normalizeValue() PHP
     * method expects, so it needs one to reshape the value first. This
     * field's normalizeValue() already accepts a row keyed by column
     * *handle* as a fallback when the numeric colId isn't present (see its
     * own docblock) specifically so mutation input — which can only ever
     * be handle-keyed, since GraphQL field names can't start with a digit
     * — flows straight through unchanged.
     *
     * A relations column's mutation type is `listOf(id())` — a plain list
     * of element ids to relate, not the resolved element objects
     * getContentGqlType() exposes for reading; normalizeValue() already
     * accepts raw ids for these cells regardless of source.
     *
     * @return GqlType|array<string, mixed>
     */
    public function getContentGqlMutationArgumentType(): GqlType|array
    {
        $typeName = $this->handle() . '_SmartTableRowInput';

        /** @var GqlType $rowType */
        $rowType = GqlEntityRegistry::getOrCreate($typeName, function() use ($typeName) {
            $fields = $this->rowGqlFields($this->columnGqlMutationType(...));

            return new InputObjectType([
                'name' => $typeName,
                'description' => sprintf('Defines a row within the "%s" Smart Table field\'s data.', $this->name),
                'fields' => fn() => $fields,
            ]);
        });

        return GqlType::listOf($rowType);
    }

    /**
     * Shared field-map builder for getContentGqlType() and
     * getContentGqlMutationArgumentType() — both seed the same reserved
     * isHeader/rowClass metadata fields and walk $columns the same way,
     * differing only in how a column's own type resolves to a GqlType.
     * One copy of that seed means a third reserved row-metadata field
     * can't be added to one row shape while forgotten on the other.
     *
     * @param callable(string): GqlType $columnType
     * @return array<string, GqlType>
     */
    private function rowGqlFields(callable $columnType): array
    {
        $fields = [
            'isHeader' => GqlType::boolean(),
            'rowClass' => GqlType::string(),
        ];
        foreach ($this->columns as $column) {
            $handle = $this->columnHandle($column);
            if ($handle === '') {
                continue;
            }
            $fields[$handle] = $columnType($this->columnType($column));
        }
        return $fields;
    }

    /**
     * JSON schema Craft 5.12's MCP server uses when an agent writes this field.
     * Rows match the GraphQL mutation input: handles, not column ids, and
     * relation cells are element ids.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        $properties = [
            'isHeader' => ['type' => 'boolean'],
            'rowClass' => ['type' => 'string'],
        ];
        foreach ($this->columns as $column) {
            $handle = $this->columnHandle($column);
            if ($handle === '') {
                continue;
            }
            $properties[$handle] = $this->columnInputSchema($this->columnType($column));
        }

        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => $properties,
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function columnInputSchema(string $type): array
    {
        if (self::relationElementClass($type) !== null) {
            return [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => 'Element ids.',
            ];
        }

        return match ($type) {
            'checkbox' => ['type' => 'boolean'],
            'number' => ['type' => 'number'],
            default => ['type' => 'string'],
        };
    }

    private function columnGqlMutationType(string $type): GqlType
    {
        if (self::relationElementClass($type) !== null) {
            return GqlType::listOf(GqlType::id());
        }

        return match ($type) {
            'checkbox' => GqlType::boolean(),
            'number' => GqlType::float(),
            'date' => GqlType::string(),
            default => GqlType::string(),
        };
    }

    private function columnGqlType(string $type): GqlType
    {
        $elementClass = self::relationElementClass($type);
        if ($elementClass !== null) {
            return match ($elementClass) {
                Entry::class => GqlType::listOf(EntryInterface::getType()),
                Asset::class => GqlType::listOf(AssetInterface::getType()),
                Category::class => GqlType::listOf(CategoryInterface::getType()),
                User::class => GqlType::listOf(UserInterface::getType()),
                default => GqlType::string(),
            };
        }

        return match ($type) {
            'checkbox' => GqlType::boolean(),
            'number' => GqlType::float(),
            'date' => GqlDateTime::getType(),
            default => GqlType::string(),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        return array_map(fn(array $type) => $type['label'], self::COLUMN_TYPES);
    }

    /**
     * The one place a column type resolves to an element class — every other
     * method that needs this (normalizeValue, serializeValue, inputHtml,
     * cellIsEmpty) calls here rather than re-deriving it, and it's public so
     * the "Add a row" AJAX fallback controller can use it too, without a
     * second copy of the type map.
     */
    public static function relationElementClass(string $typeKey): ?string
    {
        return self::COLUMN_TYPES[$typeKey]['elementType'] ?? null;
    }

    /**
     * The query-criteria key for a relation picker's results — passed
     * through Cp::elementSelectHtml()'s `criteria` to the picker modal's
     * own element query, so an out-of-scope element isn't just hidden, it's
     * unsearchable and unselectable.
     */
    private static function relationCriteriaKey(string $elementClass): string
    {
        return match ($elementClass) {
            Asset::class => 'volumeId',
            Category::class, User::class => 'groupId',
            default => 'sectionId', // Entry::class
        };
    }

    /**
     * The `"{type}:{uid}"` key format Craft's own core relation fields use
     * for the picker's `sources` (its sidebar tree of sections/volumes/
     * groups) — confirmed against craft\fields\Entries/Assets/Category/
     * User's own source-building code. `criteria` alone, without also
     * restricting `sources`, isn't enough: leaving every section/volume/
     * group selectable in the sidebar while filtering results by criteria
     * produces exactly the mismatch a real run of this surfaced — picking a
     * non-structure section's `sectionId` while the sidebar's default
     * source is still a *structure* section throws a SQL error trying to
     * sort by a `lft` column no channel-typed elements have.
     */
    private static function sourceKeyPrefix(string $elementClass): string
    {
        return match ($elementClass) {
            Asset::class => 'volume',
            Category::class, User::class => 'group',
            default => 'section', // Entry::class
        };
    }

    /**
     * Resolves a column's comma-separated "source" handles (section/volume/
     * group, matching whichever the column's element type actually has) to
     * the actual models. An empty array means "unrestricted" — either the
     * setting is blank, or every configured handle failed to resolve
     * (validateColumns() rejects an unsaveable config with an unresolvable
     * handle at settings-save time, so a handle that fails to resolve here,
     * on an already-saved field, means whatever it pointed at — a section,
     * a volume, a group — was deleted since; falling back to unrestricted
     * rather than an impossible always-empty criteria keeps the picker
     * usable instead of silently useless).
     *
     * @param ColumnConfig $column
     * @return array<int, Section|Volume|CategoryGroup|UserGroup>
     */
    private static function relationSourceModels(string $elementClass, array $column): array
    {
        $models = [];
        foreach (self::sourceHandles($column) as $handle) {
            $model = self::sourceModelByHandle($elementClass, $handle);
            if ($model !== null) {
                $models[] = $model;
            }
        }
        return $models;
    }

    /**
     * @param ColumnConfig $column
     * @return array<int, int>
     */
    private static function relationSourceIds(string $elementClass, array $column): array
    {
        $ids = [];
        foreach (self::relationSourceModels($elementClass, $column) as $model) {
            if ($model->id !== null) {
                $ids[] = $model->id;
            }
        }
        return $ids;
    }

    /**
     * @param ColumnConfig $column
     * @return array<int, string>
     */
    private static function sourceHandles(array $column): array
    {
        $raw = $column['source'] ?? null;

        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                static fn($h) => is_string($h) ? trim($h) : '',
                $raw,
            ), static fn(string $h): bool => $h !== ''));
        }

        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map(trim(...), explode(',', $raw)), static fn(string $h): bool => $h !== ''));
    }

    private static function sourceModelByHandle(string $elementClass, string $handle): Section|Volume|CategoryGroup|UserGroup|null
    {
        return match ($elementClass) {
            Entry::class => Craft::$app->getEntries()->getSectionByHandle($handle),
            Asset::class => Craft::$app->getVolumes()->getVolumeByHandle($handle),
            Category::class => Craft::$app->getCategories()->getGroupByHandle($handle),
            User::class => Craft::$app->getUserGroups()->getGroupByHandle($handle),
            default => null,
        };
    }

    /**
     * Every section/volume/category group/user group that could be picked as
     * a Relation source for this element type — the settings screen's
     * "Relation source" checklist (see sourceCellHtml()) offers exactly
     * this list, so a typo'd handle (the old free-text input's failure mode,
     * still caught for any already-saved config by validateColumns()) is no
     * longer possible from the CP.
     *
     * @return array<int, array{handle: string, name: string}>
     */
    private static function allSourcesFor(string $elementClass): array
    {
        return match ($elementClass) {
            Entry::class => array_map(
                static fn(Section $s): array => ['handle' => $s->handle ?? '', 'name' => $s->name ?? ''],
                Craft::$app->getEntries()->getAllSections(),
            ),
            Asset::class => array_map(
                static fn(Volume $v): array => ['handle' => $v->handle ?? '', 'name' => $v->name ?? ''],
                Craft::$app->getVolumes()->getAllVolumes(),
            ),
            Category::class => array_map(
                static fn(CategoryGroup $g): array => ['handle' => $g->handle ?? '', 'name' => $g->name ?? ''],
                Craft::$app->getCategories()->getAllGroups(),
            ),
            User::class => array_map(
                static fn(UserGroup $g): array => ['handle' => $g->handle ?? '', 'name' => $g->name ?? ''],
                Craft::$app->getUserGroups()->getAllGroups(),
            ),
            default => [],
        };
    }

    /**
     * Every relations type's full source list, keyed by the same type key
     * (`relations_entries` etc.) the client JS already reads off the Type
     * select's value — embedded once as JSON in getSettingsHtml() so that JS
     * can build/rebuild a "Relation source" checklist for any row,
     * old or freshly added, without a server round-trip per row.
     *
     * @return array<string, array<int, array{handle: string, name: string}>>
     */
    private function relationSourcesPayload(): array
    {
        $payload = [];
        foreach (self::COLUMN_TYPES as $typeKey => $type) {
            $elementClass = $type['elementType'] ?? null;
            if ($elementClass !== null) {
                $payload[$typeKey] = self::allSourcesFor($elementClass);
            }
        }
        return $payload;
    }

    /**
     * The source id(s) a given resolved element actually belongs to —
     * plural because a User can belong to several groups at once, unlike an
     * Entry's one section, an Asset's one volume, or a Category's one group.
     * Used both by validateRows() (does this selection intersect the
     * configured source ids?) and nowhere else, since the picker itself is
     * already constrained by relationCriteriaKey()'s criteria.
     *
     * @return array<int, int>
     */
    private static function relationSourceIdsOf(string $elementClass, ElementInterface $el): array
    {
        $id = match (true) {
            $el instanceof Entry => $el->getSection()?->id,
            $el instanceof Asset => $el->getVolumeId(),
            $el instanceof Category => $el->groupId,
            default => null,
        };
        if ($id !== null) {
            return [$id];
        }
        if ($el instanceof User) {
            $ids = [];
            foreach ($el->getGroups() as $group) {
                if ($group->id !== null) {
                    $ids[] = $group->id;
                }
            }
            return $ids;
        }
        return [];
    }

    /**
     * Pure decode step, kept free of Craft:: calls so it's unit-testable
     * without an app bootstrap. Json::decodeIfJson() has no such dependency
     * either (a try/catch around yii\helpers\Json::decode()).
     *
     * @return array<int|string, mixed>
     */
    public static function decodeRows(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Builds the shared element-select widget config, so the field's own
     * cell rendering and the "Add a row" AJAX controller (which needs the
     * same shape for a blank widget) can't drift apart.
     *
     * $criteria and $sources (both built by relationPickerRestriction())
     * are the actual enforcement for a configured Relation source
     * restriction — handed straight to the picker modal's own element
     * query and sidebar tree. Both are required together, not just
     * $criteria: restricting query results while leaving every source
     * selectable in the sidebar leaves the sidebar's default selected
     * source pointed at whatever Craft picks first, which can be a
     * *structure* section while $criteria narrows results to a *channel*
     * one — a mismatch that throws a SQL error trying to sort by a `lft`
     * column channel-typed elements don't have (confirmed against a real
     * run of this). Left empty (the default) for an unrestricted column,
     * matching every other caller's existing behavior exactly.
     *
     * @param array<int, ElementInterface> $elements
     * @param array<string, mixed> $criteria
     * @param array<int, string> $sources
     * @return array{id: string, name: string, elements: array<int, ElementInterface>, elementType: string, viewMode: string, criteria?: array<string, mixed>, sources?: array<int, string>}
     */
    public static function elementSelectConfig(
        string $inputId,
        int|string $rowId,
        int|string $colId,
        string $fieldHandle,
        string $elementClass,
        array $elements,
        array $criteria = [],
        array $sources = [],
    ): array {
        $config = [
            'id' => "$inputId-$rowId-$colId",
            'name' => $fieldHandle . "[$rowId][$colId]",
            'elements' => $elements,
            'elementType' => $elementClass,
            'viewMode' => BaseRelationField::VIEW_MODE_LIST_INLINE,
        ];
        if ($criteria !== []) {
            $config['criteria'] = $criteria;
        }
        if ($sources !== []) {
            $config['sources'] = $sources;
        }
        return $config;
    }

    /**
     * @param ColumnConfig $column
     * @return array{criteria: array<string, mixed>, sources: array<int, string>}
     */
    public static function relationPickerRestriction(string $elementClass, array $column): array
    {
        $models = self::relationSourceModels($elementClass, $column);
        if ($models === []) {
            return ['criteria' => [], 'sources' => []];
        }

        $prefix = self::sourceKeyPrefix($elementClass);
        return [
            'criteria' => [self::relationCriteriaKey($elementClass) => array_map(static fn($model) => $model->id, $models)],
            'sources' => array_map(static fn($model) => "$prefix:{$model->uid}", $models),
        ];
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['minRows', 'maxRows'], 'integer', 'min' => 0];
        $rules[] = [['minRows'], 'compare', 'compareAttribute' => 'maxRows', 'operator' => '<=', 'type' => 'number', 'when' => [$this, 'hasMaxRows']];
        $rules[] = [['maxRows'], 'compare', 'compareAttribute' => 'minRows', 'operator' => '>=', 'type' => 'number', 'when' => [$this, 'hasMinRows']];
        $rules[] = [['columns'], 'validateColumns'];
        return $rules;
    }

    public function hasMinRows(): bool
    {
        return (bool)$this->minRows;
    }

    public function hasMaxRows(): bool
    {
        return (bool)$this->maxRows;
    }

    /**
     * Reserved row-metadata keys a column's handle can't reuse: `_isHeader`/
     * `_rowClass` are the raw persisted keys, `isHeader`/`rowClass` are the
     * clean aliases normalizeValue() also sets (see there) and that GraphQL
     * has always exposed via SmartTableRowType's field-name translation. A
     * column handled e.g. "isHeader" would otherwise silently overwrite one
     * of these in every row array, and — for GraphQL specifically — silently
     * replace the reserved field's type in getContentGqlType() too.
     */
    private const RESERVED_ROW_KEYS = ['isHeader', 'rowClass', '_isHeader', '_rowClass'];

    /**
     * Flags duplicate column handles — two columns sharing a handle silently
     * break handle-based front-end access, with no other error to explain why.
     * Also flags a handle that collides with a reserved row-metadata key.
     */
    public function validateColumns(): void
    {
        $seen = [];
        foreach ($this->columns as &$column) {
            $handle = $column['handle'] ?? '';
            if ($handle === '') {
                continue;
            }
            if (in_array($handle, self::RESERVED_ROW_KEYS, true)) {
                $column['handle'] = ['value' => $handle, 'hasErrors' => true];
                $this->addError('columns', Craft::t('smart-table', '“{handle}” is a reserved name and can’t be used as a column handle.', [
                    'handle' => $handle,
                ]));
            }
            if (isset($seen[$handle])) {
                $column['handle'] = ['value' => $handle, 'hasErrors' => true];
                $this->addError('columns', Craft::t('smart-table', 'Column handles must be unique: “{handle}” is used more than once.', [
                    'handle' => $handle,
                ]));
            }
            $seen[$handle] = true;
        }
        unset($column);

        // Catches a typo'd section/volume/group handle at settings-save
        // time rather than letting it degrade silently to "unrestricted"
        // the next time the field renders (see relationSourceIds()'s
        // docblock for why that's the *runtime* fallback for an
        // already-saved field whose configured source got deleted later —
        // a genuinely different situation from "never resolved at all").
        foreach ($this->columns as &$column) {
            $elementClass = self::relationElementClass($this->columnType($column));
            if ($elementClass === null) {
                continue;
            }
            foreach (self::sourceHandles($column) as $sourceHandle) {
                if (self::sourceModelByHandle($elementClass, $sourceHandle) === null) {
                    $column['source'] = ['value' => $column['source'] ?? '', 'hasErrors' => true];
                    $this->addError('columns', Craft::t('smart-table', '“{handle}” in the Relation source setting doesn’t match any {type}.', [
                        'handle' => $sourceHandle,
                        'type' => match ($elementClass) {
                            Asset::class => Craft::t('smart-table', 'volume'),
                            Category::class => Craft::t('smart-table', 'category group'),
                            User::class => Craft::t('smart-table', 'user group'),
                            default => Craft::t('smart-table', 'section'),
                        },
                    ]));
                    break;
                }
            }
        }
        unset($column);
    }

    /**
     * @return array<int, string>
     */
    public function getElementValidationRules(): array
    {
        return ['validateRows'];
    }

    public function validateRows(ElementInterface $element): void
    {
        $handle = $this->handle();
        $rows = $element->getFieldValue($handle);
        $rows = is_array($rows) ? $rows : [];

        if ($this->minRows && count($rows) < $this->minRows) {
            $element->addError($handle, Craft::t('smart-table', 'Not enough rows.'));
        }
        if ($this->maxRows && count($rows) > $this->maxRows) {
            $element->addError($handle, Craft::t('smart-table', 'Too many rows.'));
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($this->columns as $colId => $column) {
                $cell = $row[$colId] ?? null;

                if (!empty($column['required']) && $this->cellIsEmpty($this->columnType($column), $cell)) {
                    $element->addError($handle, Craft::t('smart-table', '“{heading}” is required.', [
                        'heading' => $this->columnHeading($column),
                    ]));
                }

                $this->validateRelationSource($element, $column, $cell);
            }
        }
    }

    /**
     * A relation source restriction (see relationCriteriaKey()) is enforced
     * by the picker's own criteria in the CP, but that's a client-side
     * courtesy, not a boundary — a direct POST bypasses it entirely, the
     * same way any client-side-only restriction can. This is the actual
     * enforcement: every selected element's own source(s) (see
     * relationSourceIdsOf()) must intersect the configured allow-list,
     * checked against $cell's already-resolved elements (normalizeValue()
     * ran before validateRows() is ever called) rather than re-querying.
     *
     * @param ColumnConfig $column
     */
    private function validateRelationSource(ElementInterface $element, array $column, mixed $cell): void
    {
        $elementClass = self::relationElementClass($this->columnType($column));
        if ($elementClass === null || !is_array($cell)) {
            return;
        }

        $sourceIds = self::relationSourceIds($elementClass, $column);
        if ($sourceIds === []) {
            return;
        }

        /** @var array<int, ElementInterface> $cell */
        foreach ($cell as $el) {
            if (array_intersect(self::relationSourceIdsOf($elementClass, $el), $sourceIds) === []) {
                $element->addError($this->handle(), Craft::t('smart-table', '“{title}” isn’t allowed in the “{heading}” column.', [
                    'title' => (string)$el,
                    'heading' => $this->columnHeading($column),
                ]));
            }
        }
    }

    /**
     * $columns entries come from decoded settings JSON / POST data, so
     * PHPStan (correctly) only knows each value is mixed — these narrow the
     * three keys read as strings throughout this class.
     *
     * @param ColumnConfig $column
     */
    private function columnType(array $column): string
    {
        $type = $column['type'] ?? null;
        return is_string($type) ? $type : 'singleline';
    }

    /**
     * @param ColumnConfig $column
     */
    private function columnHeading(array $column): string
    {
        $heading = $column['heading'] ?? null;
        return is_string($heading) ? $heading : '';
    }

    /**
     * @param ColumnConfig $column
     */
    private function columnHandle(array $column): string
    {
        $handle = $column['handle'] ?? null;
        return is_string($handle) ? $handle : '';
    }

    /**
     * Parses a Dropdown column's comma-separated "options" setting into the
     * value => label map _includes/forms/select.twig expects (a bare list
     * like `['Red', 'Green']` would render options valued "0"/"1" instead —
     * select.twig falls back to the array key as the option value when
     * `option.value` isn't set).
     *
     * @param ColumnConfig $column
     * @return array<string, string>
     */
    private function selectOptions(array $column): array
    {
        $raw = is_string($column['options'] ?? null) ? $column['options'] : '';
        $values = array_values(array_filter(
            array_map(trim(...), explode(',', $raw)),
            static fn(string $value): bool => $value !== '',
        ));
        return array_combine($values, $values);
    }

    private function cellIsEmpty(string $type, mixed $value): bool
    {
        if (self::relationElementClass($type) !== null) {
            return empty($value);
        }
        return $value === null || $value === '';
    }

    /**
     * Resolves every relations column's stored IDs into real elements, one
     * query per *element class* across the whole field — not one query per
     * column, and not one per cell. Two "Relations (Entries)" columns share
     * a single Entry query rather than issuing two, since Craft's element
     * IDs are one global sequence and the two columns' ID sets can simply
     * be unioned before querying — for R rows and C relation columns, this
     * is at most one query per distinct type used, not R×C or even C.
     *
     * Craft's own save pipeline calls normalizeValue() on a field's value
     * more than once per request — confirmed via craft\base\Element::
     * getFieldValue() -> normalizeFieldValue() being called again during
     * _saveElementInternal(), by which point $value is already this
     * method's own prior output (real elements, not raw IDs). Cells already
     * holding elements are passed through instead of re-queried.
     *
     * This only batches within a single call — reading this field across
     * many elements in one request (e.g. a Twig loop over a large entry
     * list) still costs one such batch per element, since Craft hands
     * normalizeValue() one element's value at a time with no way to see the
     * others. See the README's "Performance" section for why that's not
     * fixed here and what to do if it matters for a given use case.
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        /** @var array<int|string, array<int|string, mixed>> $rows */
        $rows = array_filter(self::decodeRows($value), is_array(...));

        /** @var array<int|string, array<int|string, array<int>>> $idsByRowByCol keyed by colId, then rowId */
        $idsByRowByCol = [];
        /** @var array<class-string<ElementInterface>, array<int>> $idsByClass */
        $idsByClass = [];
        /** @var array<int|string, class-string<ElementInterface>> $classByCol */
        $classByCol = [];

        foreach ($this->columns as $colId => $column) {
            $elementClass = self::relationElementClass($this->columnType($column));
            if ($elementClass === null) {
                continue;
            }
            $classByCol[$colId] = $elementClass;
            // Set unconditionally, not just when a row actually contributes
            // ids: with zero rows (or all-empty cells) for this class, the
            // row loop below never runs, and without this the later
            // per-column lookup would hit a class that was never added to
            // $idsByClass at all -- an empty result is still a result.
            $idsByClass[$elementClass] ??= [];

            $handle = $this->columnHandle($column);

            $idsByRow = [];
            foreach ($rows as $rowId => $row) {
                // A GraphQL mutation's input rows (see
                // getContentGqlMutationArgumentType()) are keyed only by
                // handle — GraphQL field names can't start with a digit, so
                // there's no numeric-colId equivalent to send. Falling back
                // to the handle key here (and in the coercion pass below)
                // is what lets that input flow straight through
                // normalizeValue() with no separate GQL-layer reshaping
                // step, the same way craft\fields\Table's own
                // _normalizeValueInternal() already reads either key.
                $raw = $row[$colId] ?? ($handle !== '' ? ($row[$handle] ?? null) : null);
                $cell = is_array($raw) ? $raw : [];
                if (isset($cell[0]) && $cell[0] instanceof ElementInterface) {
                    continue;
                }
                $ids = array_filter(array_map(intval(...), $cell));
                $idsByRow[$rowId] = $ids;
                // array_push(...$ids) appends without rebuilding the whole
                // accumulated array each row, unlike array_merge() here —
                // matters once a column has many rows (id order doesn't
                // matter: only used as a set, below).
                array_push($idsByClass[$elementClass], ...$ids);
            }
            $idsByRowByCol[$colId] = $idsByRow;
        }

        $elementsByClassById = [];
        foreach ($idsByClass as $elementClass => $ids) {
            $elementsByClassById[$elementClass] = [];
            if ($ids === []) {
                continue;
            }
            foreach ($elementClass::find()->id(array_unique($ids))->all() as $el) {
                $elementsByClassById[$elementClass][$el->id] = $el;
            }
        }

        foreach ($idsByRowByCol as $colId => $idsByRow) {
            $elementsById = $elementsByClassById[$classByCol[$colId]];
            foreach ($idsByRow as $rowId => $ids) {
                $rows[$rowId][$colId] = array_values(array_filter(array_map(
                    static fn($id) => $elementsById[$id] ?? null,
                    $ids
                )));
            }
        }

        // Coerce date/checkbox/number cells to a real PHP type (DateTime,
        // bool, int|float) in the same pass that indexes each cell by column
        // handle too, alongside its numeric colId, so a template can write
        // row.myHandle instead of the less legible row[2] — the same
        // dual-indexing craft\fields\Table uses. Numeric keys stay:
        // inputHtml()/serializeValue() rely on them for form field names and
        // storage, and serializeValue() drops the handle-keyed copies again
        // before persisting. Coercion is idempotent (DateTimeHelper::
        // toDateTime() and the bool/numeric casts all pass an already-
        // coerced value straight through), matching how the relation
        // resolution above already tolerates running twice per request.
        foreach ($rows as $rowId => &$row) {
            // Clean aliases for the two reserved row-metadata keys, matching
            // the unprefixed names GraphQL has always used (SmartTableRowType
            // translates `_isHeader`/`_rowClass` to `isHeader`/`rowClass` at
            // resolve time) — added here so Twig gets the same names instead
            // of the underscore-prefixed raw storage keys. Reads either the
            // underscored or clean key as input too (a GraphQL mutation's
            // row — see getContentGqlMutationArgumentType() — only ever
            // sends the clean one), so this doubles as the input side of the
            // same aliasing, not just the output side. The underscored keys
            // are then written back from the now-resolved clean value —
            // serializeValue() only ever reads _isHeader/_rowClass, so
            // without this, a GraphQL-mutation-originated row (which never
            // had an _isHeader key to begin with) would normalize isHeader
            // correctly but persist as if it were never set.
            $row['isHeader'] = !empty($row['_isHeader']) || !empty($row['isHeader']);
            $row['_isHeader'] = $row['isHeader'] ? '1' : '';
            $rawRowClass = $row['_rowClass'] ?? $row['rowClass'] ?? null;
            $row['rowClass'] = is_string($rawRowClass) ? $rawRowClass : '';
            $row['_rowClass'] = $row['rowClass'];

            foreach ($this->columns as $colId => $column) {
                $handle = $this->columnHandle($column);
                $hasColId = array_key_exists($colId, $row);
                $hasHandle = $handle !== '' && array_key_exists($handle, $row);
                if (!$hasColId && !$hasHandle) {
                    continue;
                }
                $row[$colId] = $hasColId ? $row[$colId] : $row[$handle];

                $row[$colId] = match ($this->columnType($column)) {
                    'date' => $this->normalizeDateCell($row[$colId]),
                    'checkbox' => !empty($row[$colId]),
                    'number' => $this->normalizeNumberCell($row[$colId]),
                    'richtext' => $this->purifyRichTextCell($row[$colId]),
                    default => $row[$colId],
                };

                if ($handle !== '') {
                    $row[$handle] = $row[$colId];
                }
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * A bare date string (or a date-only array with no explicit timezone) has
     * no timezone of its own — it's ambiguous what moment it names. Without
     * assumeSystemTimeZone, DateTimeHelper::toDateTime() resolves that
     * ambiguity by treating it as UTC midnight, then converts to the app's
     * timezone for the returned DateTime; for any timezone behind UTC, that
     * conversion rolls the calendar day back by one (2026-01-15 becomes
     * 2026-01-14). This column has no time-of-day picker — editableTable.twig
     * renders it via _includes/forms/date, which posts exactly this kind of
     * bare, timezone-less string — so the only sane reading of "January 15"
     * is January 15 in the app's own timezone, not in UTC.
     */
    private function normalizeDateCell(mixed $value): ?DateTime
    {
        if ($value instanceof DateTime) {
            return $value;
        }
        if (!is_array($value) && !is_int($value) && !is_string($value) && $value !== null) {
            return null;
        }
        $date = DateTimeHelper::toDateTime($value, assumeSystemTimeZone: true);
        return $date !== false ? $date : null;
    }

    private function normalizeNumberCell(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }

    /**
     * The richtext column's toolbar only offers bold/italic/link/bulleted
     * list (see smart-table-field.js's EDITOR_CONFIG), but that's a client-side
     * UI restriction, not a security boundary — CKEditor5.craftcms.create()
     * runs entirely in the browser, and the field's own <textarea> is a
     * normal form field, so any POST request can put arbitrary HTML in this
     * cell regardless of what the toolbar allows. Templates render this
     * value with |raw (see README), so an unpurified cell is a stored-XSS
     * hole for anyone with access to this field. HtmlPurifier::process()
     * uses Craft's default HTML Purifier config, which keeps ordinary
     * markup (the toolbar's own output included) and strips everything
     * that isn't — scripts, event handlers, javascript: URLs.
     */
    private function purifyRichTextCell(mixed $value): string
    {
        return is_string($value) ? HtmlPurifier::process($value) : '';
    }

    /**
     * Rebuilds each row from known keys only (numeric colId + the two
     * reserved row-metadata keys), rather than mutating the incoming row
     * in place — normalizeValue() also writes each cell under its column's
     * handle for template convenience, and passing that through unchanged
     * would persist every cell twice, growing on every save. Table field
     * uses the same rebuild-from-scratch approach for the same reason.
     */
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if (!is_array($value)) {
            return null;
        }

        $rows = [];
        foreach ($value as $rowId => $row) {
            if (!is_array($row)) {
                continue;
            }
            $serializedRow = [
                '_isHeader' => $row['_isHeader'] ?? '',
                '_rowClass' => $row['_rowClass'] ?? '',
            ];
            foreach ($this->columns as $colId => $column) {
                $type = $this->columnType($column);
                $cell = $row[$colId] ?? null;
                if (self::relationElementClass($type) !== null && is_array($cell)) {
                    $cell = self::relationCellToIds($cell);
                } elseif ($type === 'date' && $cell instanceof DateTime) {
                    $cell = $cell->format(DateTime::ATOM);
                }
                $serializedRow[$colId] = $cell;
            }
            $rows[$rowId] = $serializedRow;
        }

        return $rows;
    }

    /**
     * A portable, human-readable row shape — keyed by column handle (not
     * the numeric colId every other internal representation uses),
     * relations as plain arrays of element ids, dates as ISO 8601 strings.
     * This is the CSV/JSON export format, and — via rowsFromImport(),
     * its exact inverse — the import format too, so the two halves of
     * "get data in and out of this field" share one shape instead of
     * drifting apart.
     *
     * A column with no handle is skipped: there's no meaningful key to
     * export it under, the same reason getContentGqlType() skips it.
     *
     * @param array<int|string, mixed> $rows normalizeValue()'s output
     * @return array<int, array<string, mixed>>
     */
    public function rowsForExport(array $rows): array
    {
        $exported = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out = [
                'isHeader' => !empty($row['_isHeader']),
                'rowClass' => is_string($row['_rowClass'] ?? null) ? $row['_rowClass'] : '',
            ];
            foreach ($this->columns as $colId => $column) {
                $handle = $this->columnHandle($column);
                if ($handle === '') {
                    continue;
                }
                $out[$handle] = $this->exportCellValue($this->columnType($column), $row[$colId] ?? null);
            }
            $exported[] = $out;
        }
        return $exported;
    }

    private function exportCellValue(string $type, mixed $value): mixed
    {
        if (self::relationElementClass($type) !== null) {
            return is_array($value) ? self::relationCellToIds($value) : [];
        }
        if ($value instanceof DateTime) {
            return $value->format(DateTime::ATOM);
        }
        return $value;
    }

    /**
     * A relations cell can hold either resolved elements (normalizeValue()'s
     * own output) or raw ids (redisplaying a validation error before
     * normalizeValue() ran) — used by both serializeValue() and
     * exportCellValue() to flatten either shape down to plain int ids.
     *
     * @param array<int, mixed> $cell
     * @return array<int, int>
     * @throws InvalidArgumentException if an entry is neither a resolved
     * element nor a scalar id — a malformed JSON import, most likely, since
     * normalizeValue() never produces anything else in this cell.
     */
    private static function relationCellToIds(array $cell): array
    {
        $ids = [];
        foreach ($cell as $el) {
            if ($el instanceof ElementInterface) {
                $id = $el->id;
            } elseif (is_scalar($el)) {
                $id = (int)$el;
            } else {
                throw new InvalidArgumentException('Relation cell entry must be an element or a scalar id, got ' . get_debug_type($el) . '.');
            }
            // An unsaved element (no real id yet) has nothing to store —
            // not malformed input, just nothing to relate to.
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * The inverse of rowsForExport(): accepts that same handle-keyed shape
     * and rebuilds the numeric-colId-keyed raw shape normalizeValue()/
     * serializeValue() expect — the same shape a real CP form POST
     * produces. No per-type coercion happens here; normalizeValue() already
     * handles turning a raw string/array into a real DateTime/bool/element
     * list robustly (it has to, to survive a validation-error redisplay),
     * so importing just hands it the same kind of raw material and lets it
     * do that work once, in one place, rather than duplicating type
     * coercion here.
     *
     * @param array<int, mixed> $rows rowsForExport()'s shape (or hand-authored data in the same shape)
     * @return array<int, array<int|string, mixed>>
     */
    public function rowsFromImport(array $rows): array
    {
        $colIdByHandle = [];
        foreach ($this->columns as $colId => $column) {
            $handle = $this->columnHandle($column);
            if ($handle !== '') {
                $colIdByHandle[$handle] = $colId;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $raw = [
                '_isHeader' => !empty($row['isHeader']) ? '1' : '',
                '_rowClass' => is_string($row['rowClass'] ?? null) ? $row['rowClass'] : '',
            ];
            foreach ($colIdByHandle as $handle => $colId) {
                if (array_key_exists($handle, $row)) {
                    $raw[$colId] = $row[$handle];
                }
            }
            $result[] = $raw;
        }
        return $result;
    }

    /**
     * Guards the CP's Import CSV/JSON control (unlike the console import
     * command, which trusts a developer running it deliberately) against a
     * file whose columns don't actually line up with this field's own
     * configured handles — the wrong field's export, a hand-edited header
     * with a typo, or a renamed column would otherwise import "successfully"
     * while silently dropping or shifting data, since rowsFromImport() just
     * ignores any handle it doesn't recognize and leaves any handle it
     * doesn't find unset.
     *
     * Checked against the union of every row's keys, not just the first
     * row's — a hand-authored JSON import isn't guaranteed to repeat the
     * same keys on every row the way a CSV's single header line does.
     * Skipped entirely for an empty import: replacing a field's rows with
     * zero rows is a valid, deliberate "clear the table" import, and there's
     * no header to validate against.
     *
     * @param array<int, mixed> $exportedRows rowsForExport()'s shape (or hand-authored data in the same shape), as parsed from the uploaded file
     * @throws InvalidArgumentException if a column handle this field defines is missing from every row, or a row has a key that's neither a known handle nor the reserved `isHeader`/`rowClass` keys
     */
    public function assertImportColumnsMatch(array $exportedRows): void
    {
        if ($exportedRows === []) {
            return;
        }

        $knownHandles = [];
        foreach ($this->columns as $column) {
            $handle = $this->columnHandle($column);
            if ($handle !== '') {
                $knownHandles[$handle] = true;
            }
        }

        $presentKeys = [];
        foreach ($exportedRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                $presentKeys[$key] = true;
            }
        }

        $missing = array_keys(array_diff_key($knownHandles, $presentKeys));
        $unknown = array_keys(array_diff_key($presentKeys, $knownHandles, ['isHeader' => true, 'rowClass' => true]));

        if ($missing === [] && $unknown === []) {
            return;
        }

        $reasons = [];
        if ($missing !== []) {
            $reasons[] = 'missing column(s): ' . implode(', ', $missing);
        }
        if ($unknown !== []) {
            $reasons[] = 'unrecognized column(s): ' . implode(', ', $unknown);
        }

        throw new InvalidArgumentException(
            'Import file columns don\'t match this field\'s columns (' . implode('; ', $reasons) . ').'
        );
    }

    /**
     * CSV can't hold a relations cell's array of ids directly, so it's
     * joined with `;` — a comma would collide with the CSV delimiter
     * itself. rowsFromCsv() splits on the same character to reverse this.
     *
     * @param array<int, array<string, mixed>> $exportedRows rowsForExport()'s shape
     */
    public function rowsToCsv(array $exportedRows): string
    {
        $columns = $this->exportColumnHandles();
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new InvalidConfigException('Could not open a temporary stream for CSV export.');
        }

        fputcsv($fh, ['isHeader', 'rowClass', ...$columns]);
        foreach ($exportedRows as $row) {
            $line = [
                !empty($row['isHeader']) ? '1' : '',
                is_string($row['rowClass'] ?? null) ? $row['rowClass'] : '',
            ];
            foreach ($columns as $handle) {
                $value = $row[$handle] ?? '';
                if (is_array($value)) {
                    $line[] = implode(';', array_map(strval(...), $value));
                } elseif (is_bool($value)) {
                    $line[] = $value ? '1' : '';
                } elseif (is_scalar($value)) {
                    $line[] = $value;
                } else {
                    $line[] = '';
                }
            }
            fputcsv($fh, $line);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv === false ? '' : $csv;
    }

    /**
     * The inverse of rowsToCsv() — parses a CSV string built the same way
     * (or by hand, matching the same header row) back into rowsForExport()'s
     * shape, ready to pass to rowsFromImport(). A relations column's cell
     * splits back into an array of ids on `;`; an empty cell becomes `[]`,
     * not `['']` (explode() on an empty string would otherwise produce
     * that).
     *
     * @return array<int, array<string, mixed>>
     * @throws InvalidArgumentException if a data line's column count doesn't
     * match the header — a hand-edited CSV's most likely mistake. Surfaced
     * here rather than silently dropping the line, so a bad import fails
     * clearly instead of quietly landing fewer rows than the file has.
     */
    public function rowsFromCsv(string $csv): array
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new InvalidConfigException('Could not open a temporary stream for CSV import.');
        }
        fwrite($fh, $csv);
        rewind($fh);

        // A leading blank line parses as a single null field, same as a
        // trailing one — skip it here too, rather than misreading it as a
        // one-column header and rejecting every real row that follows.
        do {
            $header = fgetcsv($fh);
        } while ($header === [null] && !feof($fh));
        if ($header === false || $header === [null]) {
            fclose($fh);
            return [];
        }

        $relationHandles = [];
        foreach ($this->columns as $column) {
            $handle = $this->columnHandle($column);
            if ($handle !== '' && self::relationElementClass($this->columnType($column)) !== null) {
                $relationHandles[$handle] = true;
            }
        }

        $rows = [];
        $lineNumber = 1;
        while (($line = fgetcsv($fh)) !== false) {
            $lineNumber++;
            // A blank line parses as a single null field, indistinguishable
            // from a genuine empty value in a one-column table. Only treat it
            // as a harmless trailing artifact (a text editor's final newline)
            // when nothing follows it; otherwise let it hit the column-count
            // check below like any other line.
            if ($line === [null] && feof($fh)) {
                continue;
            }
            $line = array_map(static fn($value) => $value ?? '', $line);
            if (count($line) !== count($header)) {
                fclose($fh);
                throw new InvalidArgumentException(
                    "Malformed CSV: line $lineNumber has " . count($line) . ' column(s), expected ' . count($header) . '.'
                );
            }
            $row = array_combine($header, $line);
            $row['isHeader'] = !empty($row['isHeader']) && $row['isHeader'] !== '0';
            foreach ($relationHandles as $handle => $true) {
                if (isset($row[$handle])) {
                    $row[$handle] = $row[$handle] === '' ? [] : explode(';', $row[$handle]);
                }
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function exportColumnHandles(): array
    {
        $handles = [];
        foreach ($this->columns as $column) {
            $handle = $this->columnHandle($column);
            if ($handle !== '') {
                $handles[] = $handle;
            }
        }
        return $handles;
    }

    /**
     * Writes every relations-type column's target IDs, across every row,
     * into Craft's own `relations` table — element-level only, not per row
     * or per column (that table has no concept of "which row"; see
     * relationsCellHtml()'s docblock). This is what makes relatedTo() see
     * these relations at all; without it, only this field's own rendering
     * (which resolves relations itself in normalizeValue()) knew about them.
     *
     * Deliberately not paired with EagerLoadingFieldInterface: Craft's own
     * Element::getFieldValue() short-circuits to return the raw eager-loaded
     * ElementCollection directly for any field with eager-loaded elements,
     * bypassing normalizeValue() entirely — correct for a field whose value
     * *is* a relation collection (BaseRelationField, Matrix), but it would
     * silently replace this field's row-array value with a flat element
     * list for every consumer (Twig, GraphQL) the moment `.with()` was used.
     * relatedTo() has no such coupling — it only reads this table — so it's
     * the one half of "real Craft relations" actually reachable here.
     *
     * Scoped by sourceSiteId (like BaseRelationField's localizeRelations
     * mode), not left null/shared, because this field's content is already
     * per-site storage like any Craft field's — two sites can genuinely
     * hold different relations, and sharing rows between them would let one
     * site's save clobber another's.
     *
     * Always a full delete-then-reinsert rather than an in-place diff:
     * simpler and safer to get right than matching Craft\services\Relations
     * ::saveRelations()'s update-in-place logic, at the cost of not
     * preserving relation row IDs across saves — nothing here or in Craft's
     * own relatedTo() depends on those IDs being stable.
     */
    public function afterElementSave(ElementInterface $element, bool $isNew): void
    {
        if ($this->id !== null && $element->id !== null) {
            $targetIds = $this->relatedElementIds($element);

            Craft::$app->getDb()->transaction(function() use ($element, $targetIds) {
                Craft::$app->getDb()->createCommand()->delete(DbTable::RELATIONS, [
                    'fieldId' => $this->id,
                    'sourceId' => $element->id,
                    'sourceSiteId' => $element->siteId,
                ])->execute();

                if ($targetIds !== []) {
                    $rows = [];
                    foreach (array_values($targetIds) as $sortOrder => $targetId) {
                        $rows[] = [$this->id, $element->id, $element->siteId, $targetId, $sortOrder + 1];
                    }
                    Craft::$app->getDb()->createCommand()->batchInsert(
                        DbTable::RELATIONS,
                        ['fieldId', 'sourceId', 'sourceSiteId', 'targetId', 'sortOrder'],
                        $rows,
                    )->execute();
                }
            });
        }

        parent::afterElementSave($element, $isNew);
    }

    /**
     * Reuses serializeValue()'s output (numeric-int IDs, never element
     * objects) rather than re-walking $element's in-memory field value
     * directly, so this can't drift from whatever shape actually got
     * persisted to the content column moments earlier in the same save.
     *
     * @return array<int, int>
     */
    private function relatedElementIds(ElementInterface $element): array
    {
        $serialized = $this->serializeValue($element->getFieldValue($this->handle()), $element);
        $rows = is_array($serialized) ? $serialized : [];

        $ids = [];
        foreach ($this->columns as $colId => $column) {
            if (self::relationElementClass($this->columnType($column)) === null) {
                continue;
            }
            foreach ($rows as $row) {
                if (is_array($row) && is_array($row[$colId] ?? null)) {
                    array_push($ids, ...array_filter(array_map(intval(...), $row[$colId])));
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * $value here is always already-normalized (element index/card views
     * read it via $element->getFieldValue(), which runs normalizeValue()
     * first) — richtext purified, relations resolved to elements, dates
     * coerced. There's no single "the interesting column" this field can
     * know about (columns are arbitrary and user-configured), so this
     * picks the first text-like column as a stand-in headline and falls
     * back to a bare row count when there isn't one (e.g. a field made
     * entirely of number/date/checkbox/relations columns).
     *
     * Enabling this (any PreviewableFieldInterface implementation) means
     * an element index page showing this field as a visible column now
     * calls getFieldValue() — and therefore normalizeValue(), and
     * therefore this field's batched relation queries — for every row
     * shown, which previously never happened for a plain index listing.
     * See the README's "Performance" section for what that costs.
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        $rows = is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
        if ($rows === []) {
            return '';
        }

        $textColId = $this->firstTextColumnId();
        if ($textColId !== null) {
            $snippets = [];
            foreach ($rows as $row) {
                $text = is_string($row[$textColId] ?? null) ? trim(StringHelper::stripHtml($row[$textColId])) : '';
                if ($text === '') {
                    continue;
                }
                $snippets[] = $text;
                if (count($snippets) >= 3) {
                    break;
                }
            }

            if ($snippets !== []) {
                $summary = implode(', ', $snippets);
                $remaining = count($rows) - count($snippets);
                if ($remaining > 0) {
                    $summary .= Craft::t('smart-table', ', +{count} more', ['count' => $remaining]);
                }
                return Html::encode(StringHelper::truncate($summary, 120));
            }
        }

        return Html::encode(Craft::t('smart-table', '{count, plural, =1{# row} other{# rows}}', [
            'count' => count($rows),
        ]));
    }

    /**
     * A generic, no-data placeholder for contexts with no real element to
     * read from (e.g. a card-view designer) — just this field's own name,
     * the same fallback craft\base\Field::previewPlaceholderHtml() already
     * uses when both $value and $element are null. Always returning that,
     * rather than the base implementation's $value/$element branches, side-
     * steps the base default's real bug for this field: given a non-null
     * $value it returns that value as-is, and this field's value is an
     * array — the same "Array to string conversion" problem
     * getPreviewHtml() exists to avoid in the first place.
     */
    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        return Html::encode($this->getUiLabel());
    }

    /**
     * First column whose type renders as plain-ish text worth using as a
     * getPreviewHtml() headline — not number/date/checkbox/color/select
     * (too terse or not text at all) and not a relations type (an element,
     * not a string).
     */
    private function firstTextColumnId(): ?int
    {
        foreach ($this->columns as $colId => $column) {
            if (in_array($this->columnType($column), ['singleline', 'richtext', 'email', 'url'], true)) {
                return $colId;
            }
        }
        return null;
    }

    /**
     * The site's existing native CKEditor fields — offered as toolbar
     * sources so an author can reuse buttons they've already set up
     * elsewhere, instead of redefining them (craftcms/ckeditor is a hard
     * dependency of this plugin, so it's always available to query).
     *
     * @return CkeditorField[]
     */
    private static function ckeditorFields(): array
    {
        /** @var CkeditorField[] $fields */
        $fields = Craft::$app->getFields()->getFieldsByType(CkeditorField::class);
        return $fields;
    }

    /**
     * The toolbar Rich text columns should render, resolved from the
     * field's chosen toolbar-source field and filtered down to
     * SUPPORTED_TOOLBAR_ITEMS — a source field's other buttons (headings,
     * images, tables, nested entries, ...) need per-button config this
     * field doesn't supply, so they're dropped rather than shipped broken.
     * Falls back to DEFAULT_TOOLBAR when no source is chosen, the chosen
     * one was deleted, or filtering leaves nothing.
     *
     * @return array<int, string>
     */
    private function resolvedToolbar(): array
    {
        if (!$this->toolbarSourceFieldUid) {
            return self::DEFAULT_TOOLBAR;
        }

        $sourceField = Craft::$app->getFields()->getFieldByUid($this->toolbarSourceFieldUid);
        if (!$sourceField instanceof CkeditorField) {
            return self::DEFAULT_TOOLBAR;
        }

        $toolbar = array_values(array_intersect($sourceField->toolbar, self::SUPPORTED_TOOLBAR_ITEMS));
        return $toolbar !== [] ? $toolbar : self::DEFAULT_TOOLBAR;
    }

    public function getSettingsHtml(): ?string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(SmartTableFieldAsset::class);

        $inputId = 'columns';
        $rows = [];
        foreach ($this->columns as $colId => $column) {
            $row = $column;
            $row['source'] = $this->sourceCellHtml($inputId, $colId, $column);
            $rows[$colId] = $row;
        }

        // validateColumns() (see defineRules()) adds its error to the
        // 'columns' attribute, same as any other Yii model validator would —
        // but unlike Cp::textFieldHtml() and friends, editableTableFieldHtml()
        // doesn't infer $errors from the model on its own. Without passing it
        // explicitly, a duplicate-handle rejection saves silently as far as
        // this settings screen is concerned: the save is correctly blocked
        // (confirmed directly against the DB), a generic "Couldn't save
        // field" toast appears, but the specific reason never reaches this
        // form, right where it would actually help whoever's configuring it.
        $columnsHtml = Cp::editableTableFieldHtml([
            'label' => Craft::t('smart-table', 'Columns'),
            'instructions' => Craft::t('smart-table', 'Define the columns for this table.'),
            'id' => $inputId,
            'name' => $inputId,
            'errors' => $this->getErrors('columns'),
            'addRowLabel' => Craft::t('smart-table', 'Add a column'),
            'allowAdd' => true,
            'allowReorder' => true,
            'allowDelete' => true,
            'cols' => [
                'heading' => [
                    'heading' => Craft::t('smart-table', 'Column Heading'),
                    'type' => 'singleline',
                ],
                'handle' => [
                    'heading' => Craft::t('smart-table', 'Handle'),
                    'code' => true,
                    'type' => 'singleline',
                ],
                'type' => [
                    'headingHtml' => $this->markerHeadingHtml(Craft::t('smart-table', 'Type'), ['smarttable-settings-type-col' => true]),
                    'class' => 'thin',
                    'type' => 'select',
                    'options' => self::typeOptions(),
                ],
                'options' => [
                    'headingHtml' => $this->markerHeadingHtml(Craft::t('smart-table', 'Dropdown options'), ['smarttable-settings-options-col' => true]),
                    'info' => Craft::t('smart-table', 'Comma-separated. Only used by the Dropdown type.'),
                    'code' => true,
                    'type' => 'singleline',
                    'class' => 'smarttable-conditional-cell',
                ],
                'source' => [
                    'headingHtml' => $this->markerHeadingHtml(Craft::t('smart-table', 'Relation source'), ['smarttable-settings-source-col' => true]),
                    'info' => Craft::t('smart-table', 'Select the sections, volumes, category groups, or user groups this column’s relations are limited to. Leave blank to allow any.'),
                    'type' => 'html',
                    'class' => 'smarttable-conditional-cell',
                ],
                'width' => [
                    'heading' => Craft::t('smart-table', 'Width'),
                    'code' => true,
                    'type' => 'singleline',
                    'width' => 50,
                ],
                'required' => [
                    'heading' => Craft::t('smart-table', 'Required?'),
                    'class' => 'thin',
                    'type' => 'checkbox',
                ],
            ],
            'rows' => $rows,
        ]);

        $sourcesScript = Html::tag('script', Json::encode($this->relationSourcesPayload()), [
            'type' => 'application/json',
            'data' => ['smarttable-sources' => true],
        ]);

        $columnsHtml = Html::tag('div', $columnsHtml . $sourcesScript, ['class' => 'smarttable-settings']);

        $rowCountHtml = Cp::textFieldHtml([
            'label' => Craft::t('smart-table', 'Min Rows'),
            'id' => 'minRows',
            'name' => 'minRows',
            'type' => 'number',
            'min' => 0,
            'value' => $this->minRows,
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('smart-table', 'Max Rows'),
            'id' => 'maxRows',
            'name' => 'maxRows',
            'type' => 'number',
            'min' => 0,
            'value' => $this->maxRows,
        ]);

        $toolbarSourceOptions = array_merge(
            [['value' => '', 'label' => Craft::t('smart-table', 'Default')]],
            array_map(
                static fn(CkeditorField $field): array => [
                    'value' => $field->uid,
                    'label' => Craft::t('site', $field->name ?? $field->handle ?? '') . " ({$field->handle})",
                ],
                self::ckeditorFields(),
            ),
        );

        // A chosen source field that's since been deleted has no matching
        // <option> here — with none selected, the browser silently falls
        // back to the first option ("Default"), which then gets persisted
        // the next time this field's settings are saved for any reason.
        // Keep it selectable (visibly flagged) so the setting survives an
        // unrelated save instead of silently resetting.
        if ($this->toolbarSourceFieldUid && !in_array($this->toolbarSourceFieldUid, array_column($toolbarSourceOptions, 'value'), true)) {
            $toolbarSourceOptions[] = [
                'value' => $this->toolbarSourceFieldUid,
                'label' => Craft::t('smart-table', 'Missing field'),
            ];
        }

        $toolbarSourceHtml = Cp::selectFieldHtml([
            'label' => Craft::t('smart-table', 'Rich Text Toolbar'),
            'instructions' => Craft::t('smart-table', 'Reuse the toolbar from one of the site’s existing CKEditor fields. Buttons that need extra setup this field doesn’t provide (headings, images, tables, entries, …) are hidden automatically.'),
            'id' => 'toolbarSourceFieldUid',
            'name' => 'toolbarSourceFieldUid',
            'options' => $toolbarSourceOptions,
            'value' => $this->toolbarSourceFieldUid,
        ]);

        return $columnsHtml . $rowCountHtml . $toolbarSourceHtml;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        if (empty($this->columns)) {
            return '';
        }

        $view = Craft::$app->getView();
        // SmartTableFieldAsset depends on CkeditorAsset, which registers it too.
        $view->registerAssetBundle(SmartTableFieldAsset::class);

        $inputId = $this->getInputId();
        $rows = is_array($value) ? $value : [];

        $cols = [];
        foreach ($this->columns as $colId => $column) {
            $type = $this->columnType($column);
            $elementClass = self::relationElementClass($type);
            if ($elementClass !== null) {
                $cols[$colId] = $this->relationsColumnConfig($colId, $this->columnHeading($column), $type);
                foreach ($rows as $rowId => &$row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $row[$colId] = $this->relationsCellHtml($inputId, $rowId, $colId, $elementClass, $column, $row[$colId] ?? []);
                }
                unset($row);
            } else {
                $cols[$colId] = [
                    'heading' => $this->columnHeading($column),
                    'type' => $type,
                ];
                if ($type === 'select') {
                    $cols[$colId]['options'] = $this->selectOptions($column);
                }
                if ($type === 'date') {
                    // editableTable.twig's date cell needs a real DateTime
                    // (or null) to pre-fill correctly; a validation-error
                    // redisplay can still hand this raw POST data (the same
                    // duality resolveElements() handles for relations), so
                    // this can't just assume $value already went through
                    // normalizeValue().
                    foreach ($rows as $rowId => &$row) {
                        if (is_array($row)) {
                            $row[$colId] = $this->normalizeDateCell($row[$colId] ?? null);
                        }
                    }
                    unset($row);
                }
                if (!empty($column['width'])) {
                    $cols[$colId]['width'] = $column['width'];
                }
            }
        }

        // Always-present row metadata, not a user-configurable column type.
        // Reserved non-numeric key so it can't collide with a real column's
        // numeric ID (see the numeric-key-only note on $columns).
        //
        // Row class deliberately has no CP input at all — typing a raw CSS
        // class name is a developer concern, not a content-authoring one.
        // It's still fully readable (row.rowClass in Twig, `rowClass` over
        // GraphQL) and settable — just from a template or the API, via
        // setFieldValue()/a GraphQL mutation, not by hand in the CMS.
        //
        // isHeader is author-facing, but only from each row's action menu
        // (the CP script toggles this hidden input). A dedicated checkbox
        // column used to sit in the grid; it cost a column of width and
        // duplicated the menu item, so it was removed.
        //
        // Both stored values still have to round-trip through this form,
        // or a plain "editor saves the entry" — touching nothing about
        // this field — would silently wipe them, since serializeValue()
        // only persists what normalizeValue() saw on this save. A hidden
        // input per row (editableTable.twig's own `hiddenInputs` row key)
        // carries each value through untouched without exposing it as an
        // editable cell.
        foreach ($rows as $rowId => &$row) {
            if (!is_array($row)) {
                continue;
            }
            $row['hiddenInputs']['_rowClass'] = is_string($row['_rowClass'] ?? null) ? $row['_rowClass'] : '';
            $row['hiddenInputs']['_isHeader'] = !empty($row['_isHeader']) ? '1' : '';
        }
        unset($row);

        // editableTable.twig's own markup has no overflow containment, and
        // none of Craft's surrounding CP layout wrappers set overflow-x
        // either (checked directly in a browser) — a table wide enough
        // (several relations/richtext columns, which run wider than
        // editableTable's stock column types) doesn't just get cramped, it
        // overflows its column entirely and paints over the details
        // sidebar. Wrapping the whole rendered fragment (table + "Add a
        // row") in a scrollable container fixes that without touching
        // Craft's own `table.editable` class, which native Table fields
        // and potentially other plugins share.
        $hasRichTextColumn = in_array('richtext', array_map(
            fn(array $column): string => $this->columnType($column),
            $this->columns,
        ), true);
        $toolbarScript = $hasRichTextColumn ? Html::tag('script', Json::encode($this->resolvedToolbar()), [
            'type' => 'application/json',
            'data' => ['smarttable-toolbar' => true],
        ]) : '';

        $tableHtml = Html::tag('div', $view->renderTemplate('_includes/forms/editableTable.twig', [
            'id' => $inputId,
            'name' => $this->handle(),
            'cols' => $cols,
            'rows' => $rows,
            'static' => $inline,
            'minRows' => $this->minRows,
            'maxRows' => $this->maxRows,
            'allowAdd' => true,
            'allowDelete' => true,
            'allowReorder' => true,
        ]) . $toolbarScript, ['class' => 'smarttable-scroll']);

        return $tableHtml . Html::tag(
            'div',
            $this->exportLinksHtml($element) . $this->importControlHtml($inputId, $element, $inline),
            ['class' => 'smarttable-export-import-controls'],
        );
    }

    /**
     * Export links for the CP's DefaultController::actionExport() — only
     * once the element has an id (a brand-new, unsaved element has nothing
     * to export yet). Not shown at all for a static/inline (read-only)
     * render, matching how relations cells and rich text cells already
     * disable their own interactivity there.
     */
    private function exportLinksHtml(?ElementInterface $element): string
    {
        if ($element?->id === null) {
            return '';
        }

        $urlFor = fn(string $format) => UrlHelper::actionUrl('smart-table/default/export', [
            'elementId' => $element->id,
            'fieldHandle' => $this->handle(),
            'format' => $format,
        ]);

        return Html::tag('div', Html::a(Craft::t('smart-table', 'Export CSV'), $urlFor('csv'), [
            'class' => 'smarttable-export-link',
        ]) . Html::a(Craft::t('smart-table', 'Export JSON'), $urlFor('json'), [
            'class' => 'smarttable-export-link',
        ]), ['class' => 'smarttable-export-links']);
    }

    /**
     * The CP counterpart to exportLinksHtml() — a plain file input disguised
     * as a link (a `<label for>` pointing at a visually-hidden `<input
     * type="file">` opens the native file picker with no JS needed for that
     * part), wired up by smart-table-field.js to POST the chosen file to
     * DefaultController::actionImport() and reload the page on success.
     *
     * Gated the same way exportLinksHtml() is (only once the element has an
     * id — nothing to overwrite yet on a brand-new, unsaved element), plus
     * an explicit `!$inline` check exportLinksHtml() doesn't bother with:
     * unlike a read-only export link, an upload control that can overwrite
     * the field's saved value has no business appearing on a static/inline
     * render (a revision, a nested read-only preview) at all.
     */
    private function importControlHtml(string $inputId, ?ElementInterface $element, bool $inline): string
    {
        if ($inline || $element?->id === null) {
            return '';
        }

        $fileInputId = "$inputId-import";

        return Html::tag(
            'div',
            Html::tag('label', Craft::t('smart-table', 'Import CSV'), [
                'class' => 'smarttable-import-link',
                'for' => $fileInputId,
            ]) . Html::tag('input', '', [
                'type' => 'file',
                'id' => $fileInputId,
                'class' => 'smarttable-import-input',
                'accept' => '.csv,.json,text/csv,application/json',
                'hidden' => true,
            ]) . Html::tag('span', '', [
                'class' => 'smarttable-import-error',
                'data-smarttable-import-error' => true,
                'hidden' => true,
            ]),
            [
                'class' => 'smarttable-import-control',
                'data-smarttable-import' => true,
                'data-element-id' => $element->id,
                'data-field-handle' => $this->handle(),
            ],
        );
    }

    /**
     * Craft's table markup has no per-cell column-id attribute, so a marker
     * span embedded via the headingHtml escape hatch is how the client JS
     * finds a column's position (and, for relations, its element type) —
     * shared by both columns that need this (relations, header) so the
     * marker shape can't drift between them.
     *
     * @param array<string, mixed> $data
     */
    private function markerHeadingHtml(string $label, array $data): string
    {
        return Html::tag('span', Html::encode($label), ['data' => $data]);
    }

    /**
     * Relation cells are rendered as raw HTML (Craft's element-select widget),
     * not a form-serializable scalar, so this column maps to editableTable.twig's
     * 'html' type. The heading carries data attributes so the client JS can find
     * this column's position and element type when a new row needs a fresh
     * picker (see smart-table-field.js) — Craft's own table markup has no other
     * way to identify which column a cloned cell belongs to.
     *
     * @return array{headingHtml: string, type: string}
     */
    private function relationsColumnConfig(int|string $colId, string $heading, string $typeKey): array
    {
        return [
            'headingHtml' => $this->markerHeadingHtml($heading, [
                'smarttable-relations-col' => $colId,
                'smarttable-relations-type' => $typeKey,
            ]),
            'type' => 'html',
        ];
    }

    /**
     * Related element IDs are stored directly in this field's own JSON value,
     * not Craft's relations table (which has no concept of "which row" within
     * a field). That means no relatedTo()/eager-loading/cascade-delete for
     * these relations — a deliberate v1 tradeoff to keep the compact grid
     * (see README's "Known limitations"). A related element that gets deleted
     * simply stops resolving here and drops out of
     * storage the next time the row is saved (resolveElements() only returns
     * elements that still exist, and serializeValue() only stores what's
     * resolved) — not immediate cleanup, but not a permanent dangling ID either.
     *
     * @param ColumnConfig $column
     */
    private function relationsCellHtml(string $inputId, int|string $rowId, int|string $colId, string $elementClass, array $column, mixed $value): string
    {
        $restriction = self::relationPickerRestriction($elementClass, $column);

        return Cp::elementSelectHtml(self::elementSelectConfig(
            $inputId,
            $rowId,
            $colId,
            $this->handle(),
            $elementClass,
            $this->resolveElements($elementClass, $value),
            $restriction['criteria'],
            $restriction['sources'],
        ));
    }

    /**
     * The settings screen's "Relation source" cell — a real checkbox list of
     * this row's element type's actual sources (see allSourcesFor()), not
     * the old free-text handle list. A checkbox list, not a `<select
     * multiple>`: picking more than one source out of a `<select multiple>`
     * needs a modifier-key click most authors never discover, where a
     * checkbox list is scannable and clickable with no hidden gesture —
     * the same reasoning Craft's own native relation fields' Sources
     * setting already uses a checkbox list for. Wrapped in a fixed-height
     * scrolling container (see the CSS) so a type with many sources doesn't
     * blow out the row's height.
     *
     * Maps to editableTable.twig's 'html' column type the same way
     * relationsColumnConfig()/relationsCellHtml() already do for the value
     * grid's relations cells, since neither widget is one of editableTable's
     * own built-in cell types.
     *
     * Blank for a non-relations row (nothing to pick a source from) — the
     * settings-screen JS hides this cell's content until its row's Type is
     * one of the four relations types, via the shared
     * smarttable-conditional-cell class every row (old or freshly added)
     * already carries by default.
     *
     * `hasErrors` is validateColumns()'s own error-redisplay shape
     * (`['value' => ..., 'hasErrors' => true]`) — an array-only detail (a
     * plain list of selected handles never has a 'value' key) rather than a
     * dedicated array shape editableTable.twig understands for the 'html'
     * type itself, which reads only `cell.class ?? col.class` for its <td>,
     * never `cell.hasErrors` — so the error class has to be applied here by
     * hand, replacing (not merging with) the column's own default class.
     *
     * @param ColumnConfig $column
     * @return array{value: string, class: string}
     */
    private function sourceCellHtml(string $inputId, int|string $colId, array $column): array
    {
        $raw = $column['source'] ?? null;
        $hasErrors = is_array($raw) && array_key_exists('value', $raw) && !empty($raw['hasErrors']);
        $selectedHandles = self::sourceHandles(['source' => $hasErrors ? $raw['value'] : $raw]);

        $elementClass = self::relationElementClass($this->columnType($column));
        $sources = $elementClass !== null ? self::allSourcesFor($elementClass) : [];

        // A selected handle whose section/volume/group has since been
        // deleted has no checkbox here — with none checked, it's simply
        // absent from the next POST, silently converting a restricted
        // column into an unrestricted one the next time this field's
        // settings are saved for any reason. Add a placeholder checkbox so
        // it stays checked (and visibly flagged) unless deliberately
        // unchecked.
        $knownHandles = array_column($sources, 'handle');
        foreach ($selectedHandles as $handle) {
            if (!in_array($handle, $knownHandles, true)) {
                $sources[] = ['handle' => $handle, 'name' => Craft::t('smart-table', '{handle} (missing)', ['handle' => $handle])];
            }
        }

        $checklistHtml = trim(Cp::renderTemplate('_includes/forms/checkboxSelect.twig', [
            'id' => "$inputId-$colId-source",
            'name' => "columns[$colId][source]",
            'options' => array_map(
                static fn(array $s): array => ['value' => $s['handle'], 'label' => $s['name']],
                $sources,
            ),
            'values' => $selectedHandles,
        ]));

        return [
            'value' => Html::tag('div', $checklistHtml, ['class' => 'smarttable-source-checklist']),
            'class' => 'smarttable-conditional-cell' . ($hasErrors ? ' error' : ''),
        ];
    }

    /**
     * Accepts either raw IDs (fresh POST data, or a validation-error
     * redisplay per Field::inputHtml()'s documented contract) or already-
     * resolved elements (normalizeValue()'s own output), so callers don't
     * need to know which state the value is in. Only used for a single
     * cell's redisplay — normalizeValue() does its own batched-per-column
     * resolution rather than calling this per cell, to avoid one query per
     * cell across a whole table.
     *
     * @return array<int, ElementInterface>
     */
    private function resolveElements(string $elementClass, mixed $value): array
    {
        if (is_array($value) && isset($value[0]) && $value[0] instanceof ElementInterface) {
            return $value;
        }
        $ids = is_array($value) ? array_filter(array_map(intval(...), $value)) : [];
        return $ids ? $elementClass::find()->id($ids)->fixedOrder()->all() : [];
    }

    /**
     * Resolves a duplicated row's carried-over relation IDs back into real
     * elements for the "Duplicate row" action's AJAX cell fill
     * (DefaultController::actionRelationsCell()) — unlike resolveElements(),
     * $ids here comes from an untrusted client request rather than this
     * field's own already-validated saved content, so the same $criteria
     * the picker modal itself enforces (relationPickerRestriction()) is
     * applied to the query too. Without it, a tampered request naming an
     * element outside the column's configured Relation source restriction
     * would still render as a chip (title, thumbnail) here, even though the
     * picker modal itself would never have offered it as selectable.
     *
     * @param array<int, int> $ids
     * @param array<string, mixed> $criteria
     * @return array<int, ElementInterface>
     */
    public static function resolveRestrictedElements(string $elementClass, array $ids, array $criteria): array
    {
        if ($ids === []) {
            return [];
        }
        $query = $elementClass::find()->id($ids)->fixedOrder();
        Craft::configure($query, $criteria);
        return $query->all();
    }
}
