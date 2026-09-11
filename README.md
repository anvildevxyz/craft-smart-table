# Smart Table

A Craft CMS 5 field type for a real editable table. A cell can hold rich text or a relation, edited inline in a compact grid — not in a slideout, and not in a plain textarea.

## Requirements

- Craft CMS 5.10 or later
- PHP 8.2 or later
- `craftcms/ckeditor` ^5.7 (already a Craft 5 dependency; Smart Table reuses its editor rather than bundling its own)

## Installation

```bash
composer require anvildevxyz/craft-smart-table
php craft plugin/install smart-table
```

Working on the plugin itself? Add a path repository pointing at the plugin directory instead of requiring the published package:

```json
"repositories": {
    "smart-table": {
        "type": "path",
        "url": "plugins/craft-smart-tabel",
        "options": { "symlink": true }
    }
}
```

```bash
composer require anvildevxyz/craft-smart-table:@dev
php craft plugin/install smart-table
```

## Creating a field

1. Go to **Settings → Fields → New Field**.
2. Set the field type to **Smart Table**.
3. In the **Columns** table, add one row per column: a heading, a handle, a type, an optional width, and whether the column is required. **Dropdown options** and **Relation source** only appear for column types that use them.
4. Optionally set **Min Rows** / **Max Rows** to bound how many rows an editor can add.
5. If the field has any Rich text columns, optionally pick a **Rich Text Toolbar** — reuse the toolbar from one of the site’s existing CKEditor fields.
6. Save the field, then add it to an entry type’s field layout.

Craft’s standard **Translation Method** and **Use this field’s values as search keywords** settings apply as usual. Search keywords are Craft’s default string conversion of the row array — text-like cells contribute; related elements do not become titles.

With more than three or four columns, set an explicit **Width** on the narrower ones (a Date or Number column, say). Craft’s editable table doesn’t protect narrow column types from being squeezed by wider neighbors — the same characteristic as a native Table field, and a Relations column here is wider than most of Table’s own types.

## Column types

- **Single-line text** — a plain text cell.
- **Rich text** — a CKEditor instance, mounted inline on click. The toolbar is a field-level **Rich Text Toolbar** setting: reuse one of the site’s existing CKEditor fields, filtered to the 15 formatting buttons this field’s editor setup supports (bold, italic, underline, strikethrough, subscript, superscript, remove format, link, bulleted/numbered/to-do lists, block quote, code, code block, horizontal line). Leave it on **Default** for bold, italic, link, and bulleted list. Applies to every Rich text column in the field — there is no per-column toolbar. Before you click a cell, it shows a rendered preview of the saved HTML, not raw markup. Values are HTML-purified on save.
- **Number** — normalizes to a PHP `int`/`float` (`row.qty` is `42`, not `"42"`), or `null` if the cell isn’t numeric.
- **Date** — Craft’s native date picker. Normalizes to a `DateTime` in the app timezone (`row.when.format('Y-m-d')` works directly), so the calendar day doesn’t roll back in negative-UTC-offset zones.
- **Checkbox** — normalizes to a `bool`.
- **Dropdown** — a `<select>` cell. Set options in the column’s **Dropdown options** setting: a comma-separated list (e.g. `Red, Green, Blue`).
- **Color** — Craft’s native color picker; stored as a hex string.
- **Email** / **URL** — a plain text cell with the matching input type.
- **Relations (Entries / Assets / Categories / Users)** — Craft’s element picker, embedded in the cell. Clicking **Choose** opens the native picker modal. A cell can hold more than one related element. **Relation source** is a checkbox list of the site’s sections, volumes, category groups, or user groups (matching the column’s element type); leave nothing checked to allow any. If a configured source is later deleted, the settings screen keeps it selected and flagged as missing until someone unchecks it; the picker itself falls back to unrestricted rather than becoming always-empty.

Number, Date, Checkbox, Dropdown, Color, Email, and URL reuse Craft’s own `editableTable` cell rendering. Rich text and Relations are Smart Table’s own.

## Editing rows

- **Add**, **delete**, and **reorder** rows by drag — the same as Craft’s native Table field.
- **Duplicate row** (row action menu) clones the row directly below itself with its current values and correctly re-indexed inputs. Blocked by **Max Rows** the same way **Add a row** is.
- **Header row** (row action menu) marks the row as a header. The grid bolds it immediately; the value round-trips on save. There is no Header? checkbox column.
- A rich text cell activates its editor on click and commits when you click away. Only one rich text editor is open at a time.
- A relations cell’s picker is live immediately — no click-to-activate step.
- **Keyboard navigation.** Arrow keys move to the adjacent cell; Tab / Shift+Tab commit and move to the next or previous cell, wrapping at row edges; Enter commits and moves one row down (or opens a rich text editor); Escape cancels an in-progress rich text edit. Arrow keys inside an open rich text editor, native dropdown, or date picker keep their own behavior.
- **Paste from Excel or Google Sheets.** A tab- or newline-delimited block pasted at a focused cell fills the matching cells, growing rows as needed (respecting Max Rows) and normalizing each value the same way a manual edit would. Relations columns are skipped, so pasted text cannot corrupt an element pick.

### Row class

`rowClass` (`row.rowClass` in Twig, `rowClass` over GraphQL) is readable and settable, but has no CP input. Deciding a CSS class is normally a template’s job (`loop.index is odd`, a value in one of the row’s own columns, `row.isHeader`). Set it via `setFieldValue()`, a GraphQL mutation, or a migration instead. An editor who never touches this field will not wipe a value the API set.

## Using the field in a template

`entry.mySmartTableField` returns an array of rows — an empty array when the field has none. Each row is keyed **both** by column handle and by numeric column position (`row.myHandle` and `row[0]` return the same value), plus `isHeader` and `rowClass`. The raw `_isHeader` / `_rowClass` keys are still there for any template already reading them. A relations column resolves to real element objects:

```twig
{% for row in entry.mySmartTableField %}
  <tr class="{{ row.rowClass }}">
    {% if row.isHeader %}
      <th>{{ row.label }}</th>
    {% else %}
      <td>{{ row.label }}</td>
      <td>{{ row.content|raw }}</td>
      <td>
        {% for relatedEntry in row.linked %}
          <a href="{{ relatedEntry.url }}">{{ relatedEntry.title }}</a>
        {% endfor %}
      </td>
    {% endif %}
  </tr>
{% endfor %}
```

Only the numeric keys are persisted. Handle-keyed copies are rebuilt on every load, so renaming a column’s handle doesn’t leave stale data behind. A column can’t be named `isHeader`, `rowClass`, `_isHeader`, or `_rowClass` — the settings screen rejects those handles.

### Accessible table markup

The plugin ships a Twig macro that renders `<caption>`, `<th scope="col">` / `<th scope="row">`, and `rowClass` on the `<tr>` correctly by default:

```twig
{% import "smart-table/_macros.twig" as smartTable %}

{{ smartTable.table(entry.mySmartTableField, [
  {handle: 'label', heading: 'Label'},
  {handle: 'content', heading: 'Notes', raw: true},
  {handle: 'dueDate', heading: 'Due Date', format: 'date'},
  {handle: 'linked', heading: 'Related'},
], {caption: 'Q3 tasks', class: 'my-table'}) }}
```

`columns` lists the handles worth rendering, in display order — that list isn’t guessed from the row data. Per-column options:

- `raw: true` — the cell already contains trusted HTML (a `richtext` column, already HTML-purified); rendered with `|raw`.
- `format: 'date'` — the cell is a `DateTime`; rendered with `|date`.

A relations cell renders as a comma-separated list of links (or plain titles, for elements with no front-end URL). A checkbox cell renders as localized “Yes” / “No”. Anything more custom still needs a hand-written template.

### Styling the macro’s output

The macro emits no inline styles. Every element carries a stable BEM class:

- `smarttable-table` — the `<table>`, plus whatever `options.class` you passed
- `smarttable-table__caption`, `smarttable-table__heading` (a `<th scope="col">`)
- `smarttable-table__row`, with `smarttable-table__row--header` added for an `isHeader` row (`rowClass`, if any, comes after these)
- `smarttable-table__cell` (both `<td>` and `<th scope="row">`), with `smarttable-table__cell--header` on the latter

Use those classes directly, or `@use` the plugin’s Sass partial (`src/web/scss/_smart-table.scss`). It is not a runtime asset — nothing loads it automatically:

```scss
@use "path/to/vendor/anvildevxyz/craft-smart-table/src/web/scss/smart-table";

@use "path/to/vendor/anvildevxyz/craft-smart-table/src/web/scss/smart-table" with (
  $smart-table-border-color: #ccc,
  $smart-table-radius: 0,
);
```

Without Sass, the same values are CSS custom properties on `.smarttable-table`:

```css
.smarttable-table { --smarttable-border-color: #ccc; }
```

## Using the field in PHP

Handle-keyed rows (the same shape GraphQL mutations send) flow through `setFieldValue()`:

```php
$entry->setFieldValue('mySmartTableField', [
    [
        'label' => 'Intro',
        'isHeader' => true,
        'rowClass' => 'section',
        'linked' => [456],
        'active' => true,
    ],
]);
Craft::$app->getElements()->saveElement($entry);
```

A relations cell accepts a list of element IDs. After save, `getFieldValue()` returns resolved element objects, plus both handle and numeric keys.

## GraphQL

Each field generates its own `{handle}_SmartTableRow` type. Only columns with a handle appear — GraphQL field names can’t start with a digit. `isHeader` and `rowClass` are always present.

```graphql
{
  entry(id: 123) {
    ... on myEntryType_Entry {
      mySmartTableField {
        isHeader
        rowClass
        label
        content
        linked {
          title
          url
        }
      }
    }
  }
}
```

A relations column resolves to a list of the matching interface (`EntryInterface`, `AssetInterface`, `CategoryInterface`, or `UserInterface`). Schema scope is enforced at resolve time; anything the schema isn’t allowed to read is dropped. Eager-loading via `.with()` is not supported — see [Relations](#relations-and-crafts-relations-table).

### Mutations

The field can be written through Craft’s normal entry-save mutations. The schema needs `sections.<uid>:save` (or `:create`) for that section, the same as any other field:

```graphql
mutation {
  save_myEntryType_Entry(
    id: 123
    mySmartTableField: [
      { label: "First row", isHeader: true, rowClass: "intro", linked: ["456"], active: true }
    ]
  ) {
    id
  }
}
```

Each field generates a `{handle}_SmartTableRowInput` type with the same handle-keyed fields. A relations column’s mutation type is a list of element IDs (`linked: ["456", "789"]`), not the resolved objects the query side returns.

## Import and export

- **Export** — **Export CSV** / **Export JSON** below the table in the CP, once the entry has been saved. Both download the field’s *current saved* value — not unsaved edits — keyed by column handle, with relations as element IDs and dates as ISO 8601. CSV joins a relations cell’s IDs with `;`.
- **Import (CP)** — **Import CSV** next to the export links (also accepts `.json`). Uploading replaces the field’s current value entirely and saves immediately. A bad file shows an inline error and overwrites nothing; on success the page reloads. A file this field’s own export produced always re-imports cleanly.
- **Import (console)** — `craft smart-table/default/import <elementId> <fieldHandle> <file>` is the same pipeline for scripted loads. Format is auto-detected from the extension, or set with `--format=csv|json`.

## Validation

- **Required columns** — a row fails if a required cell is empty.
- **Min / Max Rows** — the entry fails if the row count falls outside the configured bounds.
- **Duplicate handles** — field settings fail if two columns share a handle.
- **Reserved handles** — field settings fail if a column is named `isHeader`, `rowClass`, `_isHeader`, or `_rowClass`.
- **Relation source** — field settings fail if a Relation source doesn’t match a real section, volume, or group. The entry fails if a relations cell holds an element outside its column’s configured source — that check is server-side, not just the picker.

## Relations and Craft’s relations table

A relations cell stores element IDs in the field’s own JSON. On every save, the field also writes the current, de-duplicated set of related IDs across every relations column into Craft’s `relations` table — element-level only, not per row or per column. That’s enough for `relatedTo()` to find an element through this field, and for “used by” style integrity checks.

`.with()` eager-loading is not supported. Craft’s `getFieldValue()` returns an eager-loaded element collection as the field’s entire value, skipping normalization. That’s correct for a native relation field and wrong here, where a relations column is one ingredient in a richer row. There is no way to opt out of that short-circuit.

Giving every row real element identity (the Matrix approach) would fix both row-granularity and eager-loading, and would cost the compact grid itself. That tradeoff is permanent.

If a related element is deleted, its ID stops resolving and drops out of the field’s JSON the next time the row is saved. The `relations` table row cleans up the same way Craft cleans up any relation pointing at a deleted element.

## Multi-site

The default translation method is **Not translatable**: a save on one site is the value on every site. Switch the field to **Translate for each site** when two sites should keep independent tables. A relations pick is scoped to the site it was saved on and does not leak into a `relatedTo()` query run against another site.

## Nested in Matrix

The field can live on a Matrix block. Values round-trip like they do on a top-level entry, and relations written from a nested field are scoped to the block, not the host entry. Neo nesting is not covered (Neo is not a dependency; Matrix is).

## Element index preview

Adding this field as a visible column on an Entries index (**View → Table Columns**) shows a plain-text summary: the first few rows from the first text-like column (`singleline`, `richtext`, `email`, or `url`, in column order), comma-separated, with `+N more` if there are more rows — e.g. `Launch week, Next up`. A field with no text-like column falls back to a row count (`3 rows`). An empty field shows nothing.

There is no setting to pick a different headline column.

## Performance

Within one element’s value, relation lookups are batched by element class: two “Relations (Entries)” columns cost one Entry query, and 50 rows cost the same handful of queries as 5 (measured: 6–9 queries across 1, 5, 20, and 50 rows of an 11-column field). Reading the field is what triggers this. An element index that shows this field as a column pays that cost per row.

Reading the field for N entries in one request — a Twig loop, or a GraphQL list — costs N × that handful, because Craft normalizes one element at a time and `.with()` isn’t available here. Each query is a fast indexed lookup; it just doesn’t collapse to one batch the way a native relation field’s `.with()` can.

### Large row counts in the CP

A relations column renders every row’s picker in the single request that loads the entry. Adding a row later fetches one new picker via AJAX; existing rows do not. The ceiling is DOM size and widget count (one Garnish `elementSelect` per relations cell), not request count:

| Rows | Raw server response (no browser) | Browser `DOMContentLoaded` |
|-----:|---------------------------------:|---------------------------:|
| 100  | 0.6s | ~1.2s |
| 250  | 1.0s | ~3–5s |
| 500  | 1.7s | ~8–9s |
| 750  | 2.5s | ~16s |
| 1000 | 3.6s | ~28s |

Rows in the low hundreds stay comfortable. 500 is usable but slow to load. Past ~500–750 the edit page is unpleasant to work in. This field is a hand-authored grid, not a bulk data table — import/export is the better path for hundreds of rows.

## Known limitations

- JavaScript behavior (keyboard nav, paste, duplicate, header-row toggle, rich text mounting) has no automated tests.
- Neo nesting is untested.
- Search keywords are Craft’s default string conversion of the row array, not a curated set of cell titles.
- Renaming a column type key in plugin code (as happened once during development, `relations` → `relations_entries`) silently orphans any column already using the old key. There is no migration; re-select the type by hand.

## Translations

German, French, Spanish, and Italian catalogs cover every CP string (machine-translated, pending native-speaker review).

## License

Proprietary. See [LICENSE.md](LICENSE.md): one production environment per licensed copy, copyright notice retained, no circumvention of licensing features.

## Development

```bash
composer test               # 57 unit tests, no Craft app — runs anywhere
composer test:integration    # 50 integration tests — needs a MySQL or Postgres database
```

`composer.json` pins Composer’s platform to PHP 8.2, so installing on a newer host PHP still produces a vendor tree a DDEV 8.3 container can run:

```bash
ddev exec --dir=/var/www/html/plugins/craft-smart-tabel composer test
ddev exec --dir=/var/www/html/plugins/craft-smart-tabel composer check-cs
ddev exec --dir=/var/www/html/plugins/craft-smart-tabel composer phpstan
```

The integration suite boots its own throwaway Craft application against whatever `tests/.env` points at. It does not use this project’s DDEV database. A throwaway MySQL container matches the default `.env`:

```bash
docker run --rm -d --name smart-table-test-db \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=craft_smarttable_test \
  -p 3306:3306 mysql:8.0
```

Fixtures (sections, the field, a second site) are created by the suite. Every test runs in a transaction that rolls back. CI runs against both MySQL and Postgres.
