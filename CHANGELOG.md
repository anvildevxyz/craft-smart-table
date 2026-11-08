# Release Notes for Smart Table

## 1.1.0 - 2026-11-08

### Added
- The field describes its row schema to Craft 5.12’s MCP server, so an agent can read and write cells. Relation cells are element ids. Older Craft is unchanged.

## 1.0.0 - 2026-09-11

### Added
- **A compact, spreadsheet-style grid field.** Columns for single-line text, rich text, number, date, checkbox, dropdown, color, email/URL, and relations to Entries, Assets, Categories, or Users — each cell edited inline. No slideouts, no plain textareas. Wide tables scroll instead of overflowing into the details sidebar. Date values are stored in the app timezone, so they don’t roll back a day in negative-UTC-offset zones. Column settings only show Dropdown options or Relation source when that column’s type uses them.
- **Inline editing that feels like a spreadsheet.** Arrow keys move between cells; Tab/Shift+Tab commit and move to the next or previous cell (wrapping at row edges); Enter commits and moves down, or opens a rich text editor; Escape cancels an in-progress rich text edit. Arrow keys inside an open rich text editor, native dropdown, or date picker keep their own behavior.
- **Paste from Excel or Google Sheets.** A tab- or newline-delimited block pasted at a focused cell fills the matching cells, growing rows as needed (respecting Max Rows) and normalizing each value the same way a manual edit would. Relations columns are skipped, so pasted text cannot corrupt an element pick.
- **Duplicate and header rows from the row action menu.** Duplicate clones the row below itself with its current values and correctly re-indexed inputs. Header row marks the row as a header — the grid bolds it immediately, and the value round-trips on save. `rowClass` is readable and settable via `setFieldValue()` or GraphQL, but has no CP input.
- **Relations columns can be limited to specific sources.** A Relation source checkbox list of the site’s sections, volumes, category groups, or user groups (matching the column’s element type) restricts both the CP picker and the server. Leave nothing checked to allow any. `relatedTo()` finds elements through this field: on save, related IDs from every relations column are written into Craft’s `relations` table. `.with()` eager-loading is not supported — see the README.
- **A field-level Rich Text Toolbar setting.** Reuse the toolbar from one of the site’s existing CKEditor fields, filtered to the buttons this plugin’s setup supports. Defaults to bold, italic, link, and bulleted list. Cells are HTML-purified on save. Compatible with `craftcms/ckeditor` ^5.7.
- **GraphQL read and write.** Each field gets its own row type and a matching mutation input — the standard Craft entry-save mutation shape. Relations columns accept a list of element IDs on write and return resolved elements on read, filtered to the active schema’s scope.
- **Bulk import and export.** Export CSV / Export JSON and Import CSV (JSON accepted too) on each saved field instance. Export is the current saved value; import replaces it entirely. `craft smart-table/default/import <elementId> <fieldHandle> <file>` is the same pipeline for scripted loads. A bad file errors clearly and never partially overwrites the field.
- **Element index previews.** Adding the field as a table column on an Entries index shows a comma-separated summary of the first text-like column across the first few rows, or a row count when there is no text-like column.
- **An accessible Twig macro.** `{% import "smart-table/_macros.twig" as smartTable %}` then `smartTable.table(rows, columns, options)` renders caption, column and row headers, and `rowClass` on the `<tr>`. A Sass partial (`src/web/scss/_smart-table.scss`) styles that markup.
- **German, French, Spanish, and Italian translations** for every CP string (machine-translated, pending native-speaker review).
