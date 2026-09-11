(function () {
  if (window.__smartTableInitialized) {
    return;
  }
  window.__smartTableInitialized = true;

  const DEFAULT_TOOLBAR_ITEMS = ['bold', 'italic', 'link', 'bulletedList'];

  function readJsonScript(root, selector, fallback) {
    const script = root?.querySelector(selector);
    if (!script) {
      return fallback;
    }
    try {
      return JSON.parse(script.textContent);
    } catch (e) {
      if (!(e instanceof SyntaxError)) {
        throw e;
      }
      return fallback;
    }
  }

  function jqueryData(el, key) {
    return window.jQuery && el ? window.jQuery(el).data(key) : undefined;
  }

  function waitFor(getValue, {attempts, intervalMs, isAlive}, onReady, onGiveUp) {
    const value = getValue();
    if (value) {
      onReady(value);
      return;
    }
    if (attempts > 0 && isAlive()) {
      setTimeout(() => waitFor(getValue, {attempts: attempts - 1, intervalMs, isAlive}, onReady, onGiveUp), intervalMs);
      return;
    }
    onGiveUp?.();
  }

  function readToolbarConfig(td) {
    return readJsonScript(td.closest('.smarttable-scroll'), '[data-smarttable-toolbar]', DEFAULT_TOOLBAR_ITEMS);
  }

  let ckeditorModulesPromise = null;
  function loadCkeditorModules() {
    if (!ckeditorModulesPromise) {
      ckeditorModulesPromise = Promise.all([
        import('@craftcms/ckeditor'),
        import('ckeditor5'),
      ]).then(([craftcms, ckeditor5]) => ({
        create: craftcms.create,
        plugins: [
          ckeditor5.Essentials,
          ckeditor5.Paragraph,
          ckeditor5.Clipboard,
          ckeditor5.SelectAll,
          ckeditor5.Bold,
          ckeditor5.Italic,
          ckeditor5.Underline,
          ckeditor5.Strikethrough,
          ckeditor5.Subscript,
          ckeditor5.Superscript,
          ckeditor5.RemoveFormat,
          ckeditor5.AutoLink,
          ckeditor5.LinkEditing,
          ckeditor5.LinkImage,
          craftcms.CraftLink,
          ckeditor5.List,
          ckeditor5.ListProperties,
          ckeditor5.TodoList,
          ckeditor5.BlockQuote,
          ckeditor5.Code,
          ckeditor5.CodeBlock,
          ckeditor5.HorizontalLine,
        ],
      }));
    }
    return ckeditorModulesPromise;
  }

  let active = null;

  function hideTextarea(textarea, hide) {
    textarea.hidden = hide;
    textarea.style.display = hide ? 'none' : '';
  }

  function refreshPreview(td, value) {
    let preview = td.querySelector('.editable-table-preview');
    if (!value) {
      if (preview) {
        preview.remove();
      }
      return;
    }
    if (!preview) {
      preview = document.createElement('div');
      preview.className = 'editable-table-preview';
      preview.setAttribute('aria-hidden', 'true');
      td.insertBefore(preview, td.firstChild);
    }
    preview.innerHTML = value;
  }

  function syncCellFocusability(td) {
    if (!td.classList.contains('richtext-cell')) {
      return;
    }
    const textarea = td.querySelector('textarea');
    const hasOwnFocusTarget = td.classList.contains('smarttable-active') || (textarea && !textarea.hidden);
    if (hasOwnFocusTarget || textarea?.disabled) {
      td.removeAttribute('tabindex');
    } else {
      td.setAttribute('tabindex', '0');
    }
  }

  function syncRichTextDisplay(td) {
    const textarea = td.querySelector('textarea');
    if (textarea) {
      hideTextarea(textarea, !!textarea.value);
      refreshPreview(td, textarea.value);
    }
    syncCellFocusability(td);
  }

  function closeEditor(cell, {save = true, restoreFocus = false} = {}) {
    if (!cell) {
      return;
    }
    active = null;
    if (save) {
      cell.textarea.value = cell.editor.getData();
    }
    cell.td.classList.remove('smarttable-active');
    refreshPreview(cell.td, cell.textarea.value);
    cell.editor.destroy().then(() => {
      cell.div.remove();
      hideTextarea(cell.textarea, !!cell.textarea.value);
      syncCellFocusability(cell.td);
      if (restoreFocus) {
        focusCell(cell.td);
      }
    });
  }

  function activate(td) {
    if (active) {
      closeEditor(active);
    }

    const textarea = td.querySelector('textarea');
    if (!textarea) {
      return;
    }

    hideTextarea(textarea, true);
    td.querySelector('.editable-table-preview')?.remove();
    const div = document.createElement('div');
    td.appendChild(div);
    td.classList.add('smarttable-active');
    syncCellFocusability(td);

    loadCkeditorModules()
      .then(({create, plugins}) => create(div, {plugins, toolbar: {items: readToolbarConfig(td)}}))
      .then((editor) => {
        editor.setData(textarea.value);
        const cell = {td, textarea, div, editor};
        active = cell;
        editor.editing.view.focus();

        editor.ui.focusTracker.on('change:isFocused', (evt, name, isFocused) => {
          if (!isFocused && active === cell) {
            closeEditor(cell);
          }
        });
      });
  }

  document.addEventListener('click', (event) => {
    const td = event.target.closest('.richtext-cell');
    if (!td || td.classList.contains('smarttable-active')) {
      return;
    }
    if (td.querySelector('textarea')?.disabled) {
      return;
    }
    activate(td);
  });

  document.querySelectorAll('.richtext-cell').forEach(syncRichTextDisplay);

  document.addEventListener(
    'submit',
    () => {
      if (active) {
        closeEditor(active);
      }
    },
    true
  );

  function dataRows(table) {
    return Array.from(table.querySelectorAll(':scope > tbody > tr[data-id]'));
  }

  function navigableCells(tr) {
    return Array.from(tr.children).filter((td) => td.tagName === 'TD' && !td.classList.contains('action'));
  }

  function focusableControl(td) {
    const candidates = td.querySelectorAll('textarea:not([hidden]), input:not([type=hidden]), select, button');
    for (const candidate of candidates) {
      if (!candidate.disabled) {
        return candidate;
      }
    }
    return null;
  }

  function focusCell(td) {
    (focusableControl(td) ?? td).focus();
  }

  function isOpenDatepickerInput(target) {
    return (
      target instanceof HTMLInputElement &&
      target.type === 'text' &&
      target.closest('.datewrapper') &&
      window.jQuery &&
      jQuery.datepicker &&
      jQuery.datepicker._datepickerShowing &&
      jQuery.datepicker._lastInput === target
    );
  }

  function caretAtEdge(input, edge) {
    const pos = edge === 'start' ? 0 : input.value.length;
    return input.selectionStart === pos && input.selectionEnd === pos;
  }

  function ckeditorSelectionAt(editor, edge) {
    const selection = editor.model.document.selection;
    if (!selection.isCollapsed) {
      return false;
    }
    const rootRange = editor.model.createRangeIn(editor.model.document.getRoot());
    const boundary = edge === 'start' ? rootRange.start : rootRange.end;
    return boundary.isTouching(selection.getFirstPosition());
  }

  function ckeditorHasOpenPanel(editor) {
    if (!editor.plugins.has('ContextualBalloon')) {
      return false;
    }
    return !!editor.plugins.get('ContextualBalloon').visibleView;
  }

  function arrowTakeover(target, key) {
    if (target instanceof HTMLSelectElement) {
      return false;
    }
    const isVertical = key === 'ArrowUp' || key === 'ArrowDown';
    if (isOpenDatepickerInput(target)) {
      return false;
    }
    const checksStart = key === 'ArrowUp' || key === 'ArrowLeft';
    if (active && active.td.contains(target)) {
      return checksStart ? ckeditorSelectionAt(active.editor, 'start') : ckeditorSelectionAt(active.editor, 'end');
    }
    const isTextEntry =
      target instanceof HTMLTextAreaElement ||
      (target instanceof HTMLInputElement && ['text', 'url', 'search', 'tel', 'password'].includes(target.type));
    if (isTextEntry) {
      if (isVertical) {
        return true;
      }
      return checksStart ? caretAtEdge(target, 'start') : caretAtEdge(target, 'end');
    }
    return true;
  }

  function moveFocus(table, currentTd, rowDelta, colDelta, wrap) {
    const rows = dataRows(table);
    const currentTr = currentTd.closest('tr');
    const rowIndex = rows.indexOf(currentTr);
    const cells = navigableCells(currentTr);
    const colIndex = cells.indexOf(currentTd);

    if (active) {
      closeEditor(active);
    }

    if (rowIndex === -1 || colIndex === -1) {
      return false;
    }

    let targetRow = rowIndex + rowDelta;
    let targetCol = colIndex + colDelta;
    const colCount = cells.length;

    if (wrap) {
      if (targetCol >= colCount) {
        targetCol = 0;
        targetRow += 1;
      } else if (targetCol < 0) {
        targetCol = colCount - 1;
        targetRow -= 1;
      }
    }

    if (targetRow < 0 || targetRow >= rows.length) {
      focusCell(currentTd);
      return false;
    }

    const targetTd = navigableCells(rows[targetRow])[targetCol];
    if (!targetTd) {
      focusCell(currentTd);
      return false;
    }

    focusCell(targetTd);
    return true;
  }

  function handleEscape(td) {
    if (!active || active.td !== td) {
      return;
    }
    if (ckeditorHasOpenPanel(active.editor)) {
      return;
    }
    closeEditor(active, {save: false, restoreFocus: true});
  }

  function handleEnter(event, td, table) {
    const target = event.target;
    if (target.tagName === 'BUTTON') {
      return;
    }
    if (isOpenDatepickerInput(target)) {
      return;
    }
    if (td.classList.contains('richtext-cell')) {
      if (td.classList.contains('smarttable-active')) {
        return;
      }
      if (td.querySelector('textarea')?.disabled) {
        return;
      }
      event.preventDefault();
      activate(td);
      return;
    }
    event.preventDefault();
    moveFocus(table, td, 1, 0, false);
  }

  document.addEventListener('keydown', (event) => {
    const table = event.target.closest('.smarttable-scroll table.editable');
    if (!table) {
      return;
    }
    const td = event.target.closest('td');
    if (!td || td.classList.contains('action') || !td.closest('tr[data-id]')) {
      return;
    }

    if (event.key === 'Escape') {
      handleEscape(td);
      return;
    }

    const arrowDeltas = {
      ArrowUp: [-1, 0],
      ArrowDown: [1, 0],
      ArrowLeft: [0, -1],
      ArrowRight: [0, 1],
    };
    if (event.key in arrowDeltas) {
      if (!arrowTakeover(event.target, event.key)) {
        return;
      }
      event.preventDefault();
      const [rowDelta, colDelta] = arrowDeltas[event.key];
      moveFocus(table, td, rowDelta, colDelta, false);
      return;
    }

    if (event.key === 'Tab') {
      if (moveFocus(table, td, 0, event.shiftKey ? -1 : 1, true)) {
        event.preventDefault();
      }
      return;
    }

    if (event.key === 'Enter') {
      handleEnter(event, td, table);
    }
  });

  function scanRelationsColumns(table) {
    const relations = [];

    table.querySelectorAll(':scope > thead th').forEach((th, index) => {
      const relationsMarker = th.querySelector('[data-smarttable-relations-col]');
      if (relationsMarker) {
        relations.push({
          index,
          colId: relationsMarker.getAttribute('data-smarttable-relations-col'),
          typeKey: relationsMarker.getAttribute('data-smarttable-relations-type'),
        });
      }
    });

    return relations;
  }

  function fillRelationsCell(table, td, rowId, colId, typeKey, elementIds = []) {
    const hidden = table.parentElement?.querySelector(':scope > input[type="hidden"]');
    const fieldHandle = hidden ? hidden.name : null;
    if (!fieldHandle || td.dataset.smarttableFilling) {
      return;
    }
    td.dataset.smarttableFilling = '1';
    const params = {inputId: table.id, fieldHandle, rowId, colId, typeKey};
    if (elementIds.length) {
      params.elementIds = elementIds;
    }
    Craft.sendActionRequest('GET', 'smart-table/default/relations-cell', {params}).then((response) => {
      td.innerHTML = response.data.fieldsHtml;
      Craft.appendHeadHtml(response.data.headHtml);
      Craft.appendBodyHtml(response.data.bodyHtml);
      delete td.dataset.smarttableFilling;
    }).catch(() => {
      delete td.dataset.smarttableFilling;
      Craft.cp.displayError();
    });
  }

  function selectedElementIdsFor(td) {
    const widget = jqueryData(td.querySelector('.elementselect'), 'elementSelect');
    return widget ? widget.getSelectedElementIds() : [];
  }

  function copyCellControls(sourceTd, newTd) {
    const sourceControls = sourceTd.querySelectorAll('input, textarea, select');
    const newControls = newTd.querySelectorAll('input, textarea, select');
    sourceControls.forEach((sourceEl, i) => {
      const newEl = newControls[i];
      if (!newEl) {
        return;
      }
      if (sourceEl.type === 'checkbox' || sourceEl.type === 'radio') {
        newEl.checked = sourceEl.checked;
        return;
      }
      newEl.value = sourceEl.value;
    });

    if (newTd.classList.contains('richtext-cell')) {
      syncRichTextDisplay(newTd);
    }
  }

  function duplicateRow(table, editableTable, sourceTr, relationsColumns) {
    if (!editableTable.canAddRow()) {
      editableTable.updateStatusMessage();
      return;
    }

    if (active && sourceTr.contains(active.td)) {
      closeEditor(active);
    }

    const rowId = editableTable.settings.rowIdPrefix + (editableTable.biggestId + 1);
    const $newTr = Craft.EditableTable.createRow(
      rowId,
      editableTable.columns,
      editableTable.baseName,
      {},
      editableTable.settings.allowReorder,
      editableTable.settings.allowDelete,
      editableTable.settings.staticRows,
      editableTable.settings.includeRowId
    );
    const newTr = $newTr[0];

    const relationsIndexes = new Set(relationsColumns.map(({index}) => index));
    Array.from(sourceTr.children).forEach((sourceTd, index) => {
      const newTd = newTr.children[index];
      if (!newTd || relationsIndexes.has(index)) {
        return;
      }
      copyCellControls(sourceTd, newTd);
    });

    $newTr.insertAfter(sourceTr);

    editableTable.biggestId++;
    editableTable.rowCount++;
    editableTable.sorter?.addItems($newTr);
    editableTable.createRowObj($newTr);
    editableTable.updateAllRows();
    editableTable.updateAddRowButton();

    copyHiddenHeaderState(sourceTr, newTr);

    relationsColumns.forEach(({index, colId, typeKey}) => {
      const sourceTd = sourceTr.children[index];
      const newTd = newTr.children[index];
      if (sourceTd && newTd) {
        fillRelationsCell(table, newTd, rowId, colId, typeKey, selectedElementIdsFor(sourceTd));
      }
    });

    editableTable.settings.onAddRow($newTr);
    editableTable.trigger('addRow', {$tr: $newTr});
  }

  function copyHiddenHeaderState(sourceTr, newTr) {
    const source = sourceTr.querySelector(':scope > input[type="hidden"][name*="[_isHeader]"]');
    if (!source) {
      return;
    }
    const dest = headerControl(newTr);
    if (dest) {
      dest.value = source.value;
    }
  }

  function fillEmptyRelationsCells(table, rows, relationsColumns) {
    rows.forEach((tr) => {
      relationsColumns.forEach(({index, colId, typeKey}) => {
        const td = tr.children[index];
        if (td && !td.querySelector('.elementselect') && !td.dataset.smarttableFilling) {
          fillRelationsCell(table, td, tr.dataset.id, colId, typeKey);
        }
      });
    });
  }

  function createHiddenHeaderInput(tr) {
    const named = tr.querySelector(':scope > input[type="hidden"][name*="[_rowClass]"]')
      || tr.querySelector('input[name], textarea[name], select[name]');
    if (!named) {
      return null;
    }
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = named.name.replace(/\[[^\]]+\]$/, '[_isHeader]');
    input.value = '';
    tr.appendChild(input);
    return input;
  }

  function headerControl(tr) {
    return tr.querySelector(':scope > input[type="hidden"][name*="[_isHeader]"]')
      || createHiddenHeaderInput(tr);
  }

  function isHeaderOn(control) {
    return control.value === '1';
  }

  function toggleHeaderControl(control) {
    control.value = isHeaderOn(control) ? '' : '1';
  }

  function applyRowStyling(rows) {
    rows.forEach((tr) => {
      const control = headerControl(tr);
      tr.classList.toggle('smarttable-header-row', !!(control && isHeaderOn(control)));
    });
  }

  function rowActionTrigger(tr) {
    return tr.querySelector('td.action [data-disclosure-trigger]');
  }

  function withRowDisclosureMenu(tr, onReady) {
    waitFor(
      () => jqueryData(rowActionTrigger(tr), 'disclosureMenu'),
      {attempts: 20, intervalMs: 100, isAlive: () => document.body.contains(tr)},
      onReady
    );
  }

  function addPaddedMenuGroup(menu) {
    const group = menu.addGroup(null, true);
    group.classList.add('padded');
    return group;
  }

  function addHeaderRowAction(tr) {
    const control = headerControl(tr);
    if (!control) {
      return;
    }
    withRowDisclosureMenu(tr, (actionDisclosure) => {
      const group = addPaddedMenuGroup(actionDisclosure);
      const item = actionDisclosure.addItem(
        {
          label: 'Header row',
          selected: isHeaderOn(control),
          attributes: {'aria-pressed': isHeaderOn(control) ? 'true' : 'false'},
          onActivate: () => {
            toggleHeaderControl(control);
            applyRowStyling([tr]);
          },
        },
        group
      );

      actionDisclosure.on('show', () => {
        const on = isHeaderOn(control);
        item.classList.toggle('sel', on);
        item.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    });
  }

  function addDuplicateRowAction(tr, table, editableTable, relationsColumns) {
    withRowDisclosureMenu(tr, (actionDisclosure) => {
      const group = addPaddedMenuGroup(actionDisclosure);
      actionDisclosure.addItem(
        {
          label: 'Duplicate row',
          onActivate: () => duplicateRow(table, editableTable, tr, relationsColumns),
        },
        group
      );

      const triggerBtn = rowActionTrigger(tr);
      const keepEnabled = () => {
        if (triggerBtn.disabled) {
          triggerBtn.disabled = false;
          triggerBtn.classList.remove('disabled');
        }
      };
      keepEnabled();
      new MutationObserver(keepEnabled).observe(triggerBtn, {attributes: true, attributeFilter: ['disabled', 'class']});
    });
  }

  document.querySelectorAll('.smarttable-scroll table.editable').forEach((table) => {
    const relationsColumns = scanRelationsColumns(table);

    const refreshRows = (rows) => {
      if (relationsColumns.length) {
        fillEmptyRelationsCells(table, rows, relationsColumns);
      }
      applyRowStyling(rows);
    };

    const initialRows = dataRows(table);
    refreshRows(initialRows);
    initialRows.forEach((tr) => addHeaderRowAction(tr));

    waitFor(
      () => jqueryData(table, 'editable-table'),
      {attempts: 30, intervalMs: 50, isAlive: () => document.body.contains(table)},
      (editableTable) => {
        dataRows(table).forEach((tr) => addDuplicateRowAction(tr, table, editableTable, relationsColumns));
        editableTable.on('addRow', (event) => {
          const tr = event.$tr[0];
          refreshRows([tr]);
          addDuplicateRowAction(tr, table, editableTable, relationsColumns);
          addHeaderRowAction(tr);
        });
      },
      () => {
        const tbody = table.querySelector('tbody');
        if (tbody) {
          new MutationObserver(() => refreshRows(dataRows(table))).observe(tbody, {childList: true});
        }
      }
    );
  });

  function isTruthyPasteValue(value) {
    const normalized = value.trim().toLowerCase();
    return normalized !== '' && normalized !== '0' && normalized !== 'false' && normalized !== 'no';
  }

  function writeSelectCellValue(select, rawValue) {
    const normalized = rawValue.trim().toLowerCase();
    const match = Array.from(select.options).find(
      (option) => option.value.toLowerCase() === normalized || option.text.trim().toLowerCase() === normalized
    );
    if (!match) {
      return;
    }
    select.value = match.value;
    select.dispatchEvent(new Event('change', {bubbles: true}));
  }

  function writeRichTextCellValue(td, rawValue) {
    if (active && active.td === td) {
      closeEditor(active);
    }
    const textarea = td.querySelector('textarea');
    if (!textarea) {
      return;
    }
    textarea.value = Craft.escapeHtml(rawValue);
    syncRichTextDisplay(td);
  }

  function writePastedCellValue(td, rawValue) {
    if (td.classList.contains('richtext-cell')) {
      writeRichTextCellValue(td, rawValue);
      return;
    }

    const checkbox = td.querySelector('input[type="checkbox"]');
    if (checkbox) {
      checkbox.checked = isTruthyPasteValue(rawValue);
      checkbox.dispatchEvent(new Event('change', {bubbles: true}));
      return;
    }

    const select = td.querySelector('select');
    if (select) {
      writeSelectCellValue(select, rawValue);
      return;
    }

    // Covers every remaining column type this field renders as a real,
    // directly-settable input: singleline/number (a <textarea>, per
    // editableTable.twig's default cell case) and date/color/email/url
    // (a plain <input>, per that same template's special cases for each).
    const field = td.querySelector('textarea, input:not([type="hidden"])');
    if (!field) {
      return;
    }
    field.value = rawValue;
    field.dispatchEvent(new Event('input', {bubbles: true}));
    field.dispatchEvent(new Event('change', {bubbles: true}));
  }

  function pasteBlock(table, originTr, originTd, text) {
    const originIndex = Array.from(originTr.children).indexOf(originTd);
    if (originIndex === -1) {
      return;
    }

    const relationIndexes = new Set(scanRelationsColumns(table).map((column) => column.index));
    const editableTable = jqueryData(table, 'editable-table');

    const lines = text.replace(/\r\n?/g, '\n').split('\n');
    if (lines.length > 1 && lines[lines.length - 1] === '') {
      lines.pop();
    }

    let tr = originTr;
    for (let rowOffset = 0; rowOffset < lines.length; rowOffset++) {
      if (rowOffset > 0) {
        const nextTr = tr.nextElementSibling;
        if (nextTr) {
          tr = nextTr;
        } else if (editableTable) {
          const rowObj = editableTable.addRow(false);
          if (!rowObj) {
            break;
          }
          tr = rowObj.$tr[0];
        } else {
          break;
        }
      }

      lines[rowOffset].split('\t').forEach((value, colOffset) => {
        const index = originIndex + colOffset;
        if (relationIndexes.has(index)) {
          return;
        }
        const targetTd = tr.children[index];
        if (targetTd) {
          writePastedCellValue(targetTd, value);
        }
      });
    }
  }

  document.addEventListener(
    'paste',
    (event) => {
      const td = event.target.closest('.smarttable-scroll table.editable td');
      if (!td) {
        return;
      }
      const tr = td.closest('tr[data-id]');
      const table = td.closest('table.editable');
      if (!tr || !table) {
        return;
      }
      const text = event.clipboardData?.getData('text/plain');
      if (!text || !/[\t\r\n]/.test(text)) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      pasteBlock(table, tr, td, text);
    },
    true
  );

  document.querySelectorAll('[data-smarttable-import]').forEach((control) => {
    const input = control.querySelector('input[type="file"]');
    const errorEl = control.querySelector('[data-smarttable-import-error]');
    if (!input || !errorEl) {
      return;
    }

    const showError = (message) => {
      errorEl.textContent = message;
      errorEl.hidden = !message;
    };

    const fail = (payload) => {
      showError((payload && payload.error) || Craft.t('app', 'A server error occurred.'));
      input.value = '';
    };

    input.addEventListener('change', () => {
      const file = input.files && input.files[0];
      if (!file) {
        return;
      }
      showError('');

      const formData = new FormData();
      formData.append('elementId', control.dataset.elementId);
      formData.append('fieldHandle', control.dataset.fieldHandle);
      formData.append('file', file);

      Craft.sendActionRequest('POST', 'smart-table/default/import', {data: formData})
        .then((response) => {
          if (response.data && response.data.success) {
            window.location.reload();
            return;
          }
          fail(response.data);
        })
        .catch((error) => {
          fail(error.response && error.response.data);
        });
    });
  });

  function columnIndexOf(table, selector) {
    const headers = table.querySelectorAll(':scope > thead th');
    for (let i = 0; i < headers.length; i++) {
      if (headers[i].querySelector(selector)) {
        return i;
      }
    }
    return null;
  }

  function scanSettingsColumns(table) {
    return {
      typeIndex: columnIndexOf(table, '[data-smarttable-settings-type-col]'),
      optionsIndex: columnIndexOf(table, '[data-smarttable-settings-options-col]'),
      sourceIndex: columnIndexOf(table, '[data-smarttable-settings-source-col]'),
    };
  }

  function readSourcesPayload(table) {
    return readJsonScript(table.closest('.smarttable-settings'), '[data-smarttable-sources]', {});
  }

  function isRelationsType(sourcesPayload, typeKey) {
    return Object.prototype.hasOwnProperty.call(sourcesPayload, typeKey);
  }

  function rowTypeValue(tr, typeIndex) {
    return tr.children[typeIndex]?.querySelector('select')?.value ?? '';
  }

  function updateSettingsRowVisibility(tr, {typeIndex, optionsIndex, sourceIndex}, sourcesPayload) {
    if (typeIndex === null) {
      return;
    }
    const typeKey = rowTypeValue(tr, typeIndex);
    if (optionsIndex !== null) {
      tr.children[optionsIndex]?.classList.toggle('smarttable-conditional-cell--visible', typeKey === 'select');
    }
    if (sourceIndex !== null) {
      tr.children[sourceIndex]?.classList.toggle('smarttable-conditional-cell--visible', isRelationsType(sourcesPayload, typeKey));
    }
  }

  function updateColumnCollapse(table, {typeIndex, optionsIndex, sourceIndex}, sourcesPayload) {
    if (typeIndex === null) {
      return;
    }
    const rows = dataRows(table);
    const headerRow = table.querySelector(':scope > thead > tr');

    const setColumnHidden = (index, isHidden) => {
      if (index === null) {
        return;
      }
      if (headerRow?.children[index]) {
        headerRow.children[index].hidden = isHidden;
      }
      rows.forEach((tr) => {
        if (tr.children[index]) {
          tr.children[index].hidden = isHidden;
        }
      });
    };

    setColumnHidden(optionsIndex, !rows.some((tr) => rowTypeValue(tr, typeIndex) === 'select'));
    setColumnHidden(sourceIndex, !rows.some((tr) => isRelationsType(sourcesPayload, rowTypeValue(tr, typeIndex))));
  }

  function refreshOverflowState(table, wrapper) {
    const inputDiv = table.closest('.input');
    if (!inputDiv || !wrapper) {
      return;
    }
    wrapper.classList.toggle('smarttable-has-overflow', inputDiv.scrollWidth > inputDiv.clientWidth);
  }

  function buildSourceChecklistHtml(colId, typeKey, sourcesPayload) {
    const name = `columns[${colId}][source]`;
    const items = (sourcesPayload[typeKey] || [])
      .map((source, i) => {
        const id = `columns-${colId}-source-${i}`;
        return (
          '<div class="checkbox-select-item">' +
          `<input type="checkbox" class="checkbox" id="${Craft.escapeHtml(id)}" name="${Craft.escapeHtml(name)}[]" value="${Craft.escapeHtml(source.handle)}">` +
          `<label for="${Craft.escapeHtml(id)}">${Craft.escapeHtml(source.name)}</label>` +
          '</div>'
        );
      })
      .join('');
    return (
      `<div class="smarttable-source-checklist">` +
      `<fieldset class="checkbox-select">` +
      `<input type="hidden" name="${Craft.escapeHtml(name)}">` +
      `${items}` +
      `</fieldset>` +
      `</div>`
    );
  }

  document.querySelectorAll('.smarttable-settings table.editable').forEach((table) => {
    const wrapper = table.closest('.smarttable-settings');
    const indices = scanSettingsColumns(table);
    if (indices.optionsIndex === null && indices.sourceIndex === null) {
      return;
    }
    const sourcesPayload = readSourcesPayload(table);
    const refreshRow = (tr) => updateSettingsRowVisibility(tr, indices, sourcesPayload);
    const refreshAll = () => {
      dataRows(table).forEach(refreshRow);
      updateColumnCollapse(table, indices, sourcesPayload);
      refreshOverflowState(table, wrapper);
    };

    refreshAll();

    table.addEventListener('change', (event) => {
      if (indices.typeIndex === null) {
        return;
      }
      const tr = event.target.closest('tr[data-id]');
      const typeCell = tr ? tr.children[indices.typeIndex] : null;
      if (!typeCell || !typeCell.contains(event.target)) {
        return;
      }

      refreshRow(tr);
      if (indices.sourceIndex !== null) {
        const typeKey = event.target.value;
        if (isRelationsType(sourcesPayload, typeKey)) {
          const sourceTd = tr.children[indices.sourceIndex];
          if (sourceTd) {
            sourceTd.innerHTML = buildSourceChecklistHtml(tr.dataset.id, typeKey, sourcesPayload);
          }
        }
      }
      updateColumnCollapse(table, indices, sourcesPayload);
      refreshOverflowState(table, wrapper);
    });

    const tbody = table.querySelector(':scope > tbody');
    if (tbody) {
      new MutationObserver(refreshAll).observe(tbody, {childList: true});
    }
  });
})();
