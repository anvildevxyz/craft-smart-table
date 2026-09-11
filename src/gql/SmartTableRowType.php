<?php

namespace anvildevxyz\smarttable\gql;

use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\gql\base\ObjectType;
use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * A row's GQL fields are keyed by column handle (see SmartTableField::
 * normalizeValue()'s dual-indexing), which the base ObjectType's own default
 * resolver already handles via plain array access. The only two fields that
 * need a name translation are isHeader/rowClass, which are internally
 * `_isHeader`/`_rowClass` — reserved, non-numeric keys that can't collide
 * with a user column (see SmartTableField::$columns).
 */
class SmartTableRowType extends ObjectType
{
    /**
     * @param array<string, mixed> $arguments
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (!is_array($source)) {
            return null;
        }

        $fieldName = GqlHelper::getFieldNameWithAlias($resolveInfo, $source, is_array($context) ? $context : null);

        $value = match ($fieldName) {
            'isHeader' => !empty($source['_isHeader']),
            'rowClass' => $source['_rowClass'] ?? '',
            default => $source[$fieldName] ?? null,
        };

        return is_array($value) ? self::filterByGqlSchemaScope($value) : $value;
    }

    /**
     * A relations column's cells are resolved to real elements eagerly, once
     * per column across every row (see SmartTableField::normalizeValue()),
     * not via a lazy ElementQuery the way craft\fields\BaseRelationField's
     * own GQL resolution works — which is exactly what lets a native
     * relation field's GraphQL result inherit schema-scope enforcement for
     * free, from the query itself. Eagerly-resolved objects carry no such
     * enforcement, so without this, a restricted schema would see every
     * element a cell holds regardless of whether that schema's scope allows
     * reading it. Filtered here, at GQL resolve time only, so CP rendering
     * and Twig — which read this same normalizeValue() output — are
     * unaffected.
     *
     * $value is always a resolved relations cell (SmartTableField::
     * normalizeValue() re-indexes every such cell with array_values()), so
     * its keys are always sequential ints, never strings.
     *
     * @param array<int, mixed> $value
     * @return array<int, mixed>
     */
    private static function filterByGqlSchemaScope(array $value): array
    {
        return array_values(array_filter($value, static function(mixed $element): bool {
            return match (true) {
                $element instanceof Entry => $element->getSection() !== null
                    && GqlHelper::isSchemaAwareOf(Entry::gqlScopesByContext(['section' => $element->getSection()])),
                $element instanceof Asset => GqlHelper::isSchemaAwareOf(Asset::gqlScopesByContext($element->getVolume())),
                $element instanceof Category => GqlHelper::isSchemaAwareOf(Category::gqlScopesByContext($element->getGroup())),
                $element instanceof User => GqlHelper::canQueryUsers(),
                default => true,
            };
        }));
    }
}
