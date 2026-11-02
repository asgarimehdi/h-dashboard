/**
 * MaryUI CSP-compat overrides (#1352, Step 2 / R5).
 *
 * WHY THIS EXISTS
 * ---------------
 * Six MaryUI components carried logic in `x-data` object literals that
 * the CSP-safe Alpine build cannot evaluate (method shorthand, getters,
 * `@entangle`, arrows in `x-init`/`$watch`): Main (sidebar toggle),
 * MenuSub (submenu toggle), Modal (`@entangle` open state), Table
 * (`@entangle` selection), ChoicesOffline (`@entangle` selection +
 * getters), and File (`@entangle` upload state). Their data objects
 * evaluated EMPTY, so sidebars never collapsed, modals never opened,
 * tables never selected, choices never bound, and file progress never
 * moved.
 *
 * The logic therefore lives here, in plain JS, as `Alpine.data()`
 * components registered on `alpine:init` (same static-import discipline
 * as the map store and app components). The app-side component classes
 * in `app/View/Components/Csp*.php` extend Mary's classes, override
 * `render()` with a verbatim copy of the vendor heredoc, and change
 * ONLY the `x-data` line (plus the directive expressions named in each
 * class docblock). Templates use ONLY the Step-1 proven directive
 * subset. `vendor/` is never edited.
 *
 * `@entangle` replacement: every factory syncs its bound property
 * manually — seed from `$wire` in `init()`, `$watch` the `$wire`
 * property back into local state, and `$watch` local state back into
 * `$wire` behind a JSON-equality guard so the echo cannot loop. This
 * is the same contract the unit tree picker already proves.
 */

function wireGet(wire, path) {
  return String(path).split('.').reduce((obj, part) => (obj == null ? obj : obj[part]), wire);
}

function wireSet(wire, path, value) {
  const parts = String(path).split('.');
  const last = parts.pop();
  const parent = parts.reduce((obj, part) => (obj == null ? obj : obj[part]), wire);
  if (parent != null) parent[last] = value;
}

function sameValue(a, b) {
  try {
    return JSON.stringify(a) === JSON.stringify(b);
  } catch (e) {
    return a === b;
  }
}

// Re-run `syncFn` after every Livewire DOM morph. $wire properties are
// NOT Alpine-reactive, so `$watch(() => $wire.prop)` never re-fires;
// the morph hook is the sync point for server-to-local updates.
// Returns an unregister function (or null) for destroy().
function syncOnMorph(syncFn) {
  if (window.Livewire && window.Livewire.hook) {
    const off = window.Livewire.hook('morph.updated', syncFn);
    return typeof off === 'function' ? off : null;
  }
  return null;
}

export function registerMaryComponents(Alpine) {
  // Sidebar shell (CspMain). `collapsed` is read by MenuSub through
  // the Alpine scope chain, exactly as the original literal did.
  Alpine.data('marySidebar', () => ({
    collapsed: false,
    collapseText: 'Collapse',
    collapseUrl: '',

    init() {
      const ds = this.$el.dataset;
      this.collapsed = ds.collapsed === 'true';
      this.collapseText = ds.collapseText || 'Collapse';
      this.collapseUrl = ds.collapseUrl || '';
    },

    toggle() {
      this.collapsed = !this.collapsed;
      if (this.collapseUrl) {
        fetch(this.collapseUrl + '?collapsed=' + this.collapsed);
      }
      this.$dispatch('sidebar-toggled', this.collapsed);
    },

    onMenuSubClicked() {
      if (this.collapsed) {
        this.toggle();
      }
    },
  }));

  // Submenu (CspMenuSub). Reads the parent sidebar's `collapsed`
  // through the scope chain.
  Alpine.data('maryMenuSub', () => ({
    show: false,

    init() {
      this.show = this.$el.dataset.show === 'true';
    },

    toggle() {
      // From parent Sidebar
      if (this.collapsed) {
        this.show = true;
        this.$dispatch('menu-sub-clicked');
        return;
      }

      this.show = !this.show;
    },
  }));

  // Dialog (CspModal). The `@entangle(...).live` open state is
  // replaced by the manual sync below; the `open`/`close` dispatches
  // from the original `$watch` are preserved.
  Alpine.data('maryModal', () => ({
    open: false,
    modelName: '',
    persistent: false,

    init() {
      this.modelName = this.$el.dataset.model || '';
      this.persistent = this.$el.dataset.persistent === 'true';
      if (this.modelName && this.$wire) {
        // Plain boolean state. The $wire property is NOT Alpine-reactive,
        // so a $watch on it never re-fires; instead re-read after every
        // Livewire DOM morph (server round trips land here).
        //
        // The close writes are plain-JS listeners, NOT directives:
        // `$wire.model = false` evaluated through the CSP parser is a
        // silent no-op (proven by probe: Alpine.evaluate assigns
        // without error and without effect), so the backdrop, close,
        // and Escape writes must bypass the parser entirely.
        const syncOpen = () => { this.open = !!wireGet(this.$wire, this.modelName); };
        syncOpen();
        this.$watch(
          () => this.open,
          (value) => {
            if (value) {
              this.$dispatch('open');
            } else {
              this.$dispatch('close');
            }
          },
        );
        this._morphOff = syncOnMorph(syncOpen);

        const close = () => { wireSet(this.$wire, this.modelName, false); };
        this.$el.addEventListener('click', (event) => {
          const target = event.target;
          const form = target instanceof Element ? target.closest('form') : null;
          if (!form || form.parentElement !== this.$el) {
            return;
          }
          const closeForm = this.$el.querySelector(':scope > form[method="dialog"]');
          if (form.classList.contains('modal-backdrop') || form === closeForm) {
            close();
          }
        });
        this._maryModalKeydown = (event) => {
          if (event.key === 'Escape' && this.open && !this.persistent) {
            close();
          }
        };
        window.addEventListener('keydown', this._maryModalKeydown);
      }
    },

    destroy() {
      if (typeof this._morphOff === 'function') {
        this._morphOff();
      }
      if (typeof this._maryModalKeydown === 'function') {
        window.removeEventListener('keydown', this._maryModalKeydown);
      }
    },
  }));

  // Table (CspTable, builds on AccessibleTable). The `@entangle`
  // selection is replaced by the guarded manual sync; every method is
  // verbatim from the vendor literal.
  Alpine.data('maryTable', () => ({
    selection: [],
    pageIds: [],
    isSelectable: false,
    colspanSize: 0,
    modelName: '',

    init() {
      const ds = this.$el.dataset;
      this.modelName = ds.model || '';
      this.isSelectable = ds.selectable === 'true';
      try {
        this.pageIds = JSON.parse(ds.pageIds || '[]');
      } catch (e) {
        this.pageIds = [];
      }
      if (this.modelName && this.$wire) {
        this.selection = wireGet(this.$wire, this.modelName) ?? [];
        const syncSelection = () => {
          const value = wireGet(this.$wire, this.modelName);
          if (!sameValue(value, this.selection)) {
            this.selection = value ?? [];
          }
        };
        this._morphOff = syncOnMorph(syncSelection);
        this.$watch(
          () => this.selection,
          (value) => {
            if (!sameValue(value, wireGet(this.$wire, this.modelName))) {
              wireSet(this.$wire, this.modelName, value);
            }
          },
        );
      }

      this.colspanSize = this.$refs.headers.childElementCount;

      if (this.isSelectable) {
        this.handleCheckAll();
      }
    },

    isExpanded(key) {
      return this.selection.includes(key);
    },

    isPageFullSelected() {
      return this.pageIds.length && [...this.selection]
        .sort((a, b) => b - a)
        .toString()
        .includes([...this.pageIds].sort((a, b) => b - a).toString());
    },

    toggleCheck(checked, content) {
      this.$dispatch('row-selection', { row: content, selected: checked });
      this.handleCheckAll();
    },

    toggleCheckAll(checked) {
      this.$dispatch('row-selection-all', { selected: checked });
      if (checked) {
        this.pushIds();
      } else {
        this.removeIds();
      }
    },

    toggleExpand(key) {
      if (this.selection.includes(key)) {
        this.selection = this.selection.filter((i) => i !== key);
      } else {
        this.selection.push(key);
      }
    },

    pushIds() {
      this.selection.push(...this.pageIds.filter((i) => !this.selection.includes(i)));
    },

    removeIds() {
      this.selection = this.selection.filter((i) => !this.pageIds.includes(i));
    },

    handleCheckAll() {
      this.$nextTick(() => {
        if (this.isPageFullSelected()) {
          this.$refs.mainCheckbox.checked = true;
        } else {
          this.$refs.mainCheckbox.checked = false;
        }
      });
    },

    destroy() {
      if (typeof this._morphOff === 'function') {
        this._morphOff();
      }
    },
  }));

  // Offline choices (CspChoicesOffline). The outer wrapper's
  // `{focused, selection}` literal is folded into this single factory
  // (nothing outside the inner element reads them), so there is no
  // cross-scope read to get wrong. Getters are plain-JS getters here.
  Alpine.data('maryChoices', () => ({
    focused: false,
    selection: null,
    id: '',
    options: [],
    isSingle: false,
    isSearchable: false,
    isReadonly: false,
    isDisabled: false,
    isRequired: false,
    minChars: 0,
    noResults: false,
    search: '',
    modelName: '',

    init() {
      const ds = this.$el.dataset;
      this.modelName = ds.model || '';
      this.id = ds.choicesId || '';
      this.isSingle = ds.single === 'true';
      this.isSearchable = ds.searchable === 'true';
      this.isReadonly = ds.readonly === 'true';
      this.isDisabled = ds.disabled === 'true';
      this.isRequired = ds.required === 'true';
      this.minChars = parseInt(ds.minChars || '0', 10) || 0;
      const optionsBlock = this.$el.querySelector('script.choices-options-data');
      try {
        this.options = JSON.parse((optionsBlock ? optionsBlock.textContent : '') || '[]');
      } catch (e) {
        this.options = [];
      }
      if (this.modelName && this.$wire) {
        this.selection = wireGet(this.$wire, this.modelName);
        const syncSelection = () => {
          const value = wireGet(this.$wire, this.modelName);
          if (!sameValue(value, this.selection)) {
            this.selection = value;
          }
        };
        this._morphOff = syncOnMorph(syncSelection);
        this.$watch(
          () => this.selection,
          (value) => {
            if (!sameValue(value, wireGet(this.$wire, this.modelName))) {
              wireSet(this.$wire, this.modelName, value);
            }
          },
        );
      }
      this.$watch(
        () => this.selection,
        () => {
          this.$nextTick(() => this.renderSelectionSlots());
        },
      );

      // Fix weird issue when navigating back
      document.addEventListener('livewire:navigating', () => {
        const elements = document.querySelectorAll('.mary-choices-element');
        elements.forEach((el) => el.remove());
      });

      this.$nextTick(() => this.renderSelectionSlots());
    },

    get selectedOptions() {
      return this.isSingle
        ? this.options.filter((i) => i[this.optionValueKey()] == this.selection)
        : this.selection.map((i) => this.options.filter((o) => o[this.optionValueKey()] == i)[0]);
    },

    get isAllSelected() {
      return this.options.length == this.selection.length;
    },

    get isSelectionEmpty() {
      return this.isSingle
        ? this.selection == null || this.selection == ''
        : this.selection.length == 0;
    },

    // The option value/label keys are server-rendered; the template
    // stores them as data attributes so the getters stay generic.
    optionValueKey() {
      return this.$el.dataset.optionValue || 'id';
    },

    optionLabelKey() {
      return this.$el.dataset.optionLabel || 'label';
    },

    selectAll() {
      this.selection = this.options.map((i) => i[this.optionValueKey()]);
      this.dispatchChangeEvent({ value: this.selection });
    },

    clear() {
      this.focused = false;
      this.search = '';
    },

    reset() {
      this.clear();
      if (this.isSingle) {
        this.selection = null;
      } else {
        this.selection = [];
      }

      this.dispatchChangeEvent({ value: this.selection });
    },

    focus() {
      if (this.isReadonly || this.isDisabled) {
        return;
      }

      this.focused = true;
      this.$refs.searchInput.focus();
    },

    resize() {
      this.$nextTick(() => { this.$refs.searchInput.style.width = (this.$refs.searchInput.value.length + 1) * 0.55 + 'rem'; });
    },

    isActive(id) {
      return this.isSingle
        ? this.selection == id
        : this.selection.includes(id);
    },

    toggle(id, keepOpen = false) {
      if (this.isReadonly || this.isDisabled) {
        return;
      }

      if (this.isSingle) {
        this.selection = id;
        this.focused = false;
        this.search = '';
      } else {
        if (this.selection.includes(id)) {
          this.selection = this.selection.filter((i) => i != id);
        } else {
          this.selection.push(id);
        }
      }

      this.dispatchChangeEvent({ value: this.selection });

      if (!keepOpen) {
        this.$refs.searchInput.focus();
      }
    },

    lookup() {
      Array.from(this.$refs.choicesOptions.children).forEach((child) => {
        if (!child.getAttribute('search-value').match(new RegExp(this.search, 'i'))) {
          child.classList.add('hidden');
        } else {
          child.classList.remove('hidden');
        }
      });

      this.noResults = Array.from(this.$refs.choicesOptions.querySelectorAll('div > .hidden')).length
        == Array.from(this.$refs.choicesOptions.querySelectorAll('[search-value]')).length;
    },

    dispatchChangeEvent(detail) {
      this.$refs.searchInput.dispatchEvent(new CustomEvent('change-selection', { bubbles: true, detail }));
    },

    getFocusableElements() {
      return Array.from(this.$refs.choicesOptions.querySelectorAll('[tabindex]:not([disabled])'))
        .filter((el) => el.offsetParent !== null && getComputedStyle(el).visibility !== 'hidden');
    },

    focusNext() {
      const focusableElements = this.getFocusableElements();
      const index = focusableElements.indexOf(document.activeElement);
      const nextElement = focusableElements[index + 1];

      if (nextElement) {
        nextElement.focus();
      }
    },

    focusPrevious() {
      const focusableElements = this.getFocusableElements();
      const index = focusableElements.indexOf(document.activeElement);
      const prevElement = focusableElements[index - 1];

      if (prevElement) {
        prevElement.focus();
      }
    },

    canRemove() {
      return !this.isReadonly && !this.isDisabled && !this.isSingle;
    },

    placeholderClass() {
      return (this.focused || !this.isSelectionEmpty) ? 'hidden' : '';
    },

    searchRequired() {
      return this.isRequired && this.isSelectionEmpty;
    },

    destroy() {
      if (typeof this._morphOff === 'function') {
        this._morphOff();
      }
    },

    // The selection slot's HTML cannot travel through x-html (banned
    // in the CSP build). The hidden per-option source spans are
    // rendered server-side; this copies their innerHTML into the
    // visible badges in plain JS after each render.
    renderSelectionSlots() {
      const uuid = this.$el.dataset.choicesId || '';
      this.$el.querySelectorAll('[data-selection-slot]').forEach((span) => {
        const src = document.getElementById('selection-' + uuid + '-' + span.dataset.optionValue);
        if (src) {
          span.innerHTML = src.innerHTML;
        }
      });
    },
  }));

  // File upload (CspFile). The `@entangle` file state is seeded from
  // `$wire` and watched back in; the upload-progress listener is
  // plain JS (the `x-on:livewire-upload-progress` directive would
  // pass `$event` through the parser). Crop/save/revert are verbatim.
  Alpine.data('maryFile', () => ({
    progress: 0,
    cropper: null,
    justCropped: false,
    fileChanged: false,
    imagePreview: null,
    imageCrop: null,
    originalImageUrl: null,
    cropAfterChange: false,
    file: null,
    modelName: '',

    init() {
      const ds = this.$el.dataset;
      this.modelName = ds.model || '';
      this.cropAfterChange = ds.cropAfterChange === 'true';
      if (this.modelName && this.$wire) {
        this.file = wireGet(this.$wire, this.modelName);
        this._morphOff = syncOnMorph(() => {
          this.file = wireGet(this.$wire, this.modelName);
        });
      }
      this.imagePreview = this.$refs.preview?.querySelector('img');
      this.imageCrop = this.$refs.crop?.querySelector('img');
      this.originalImageUrl = this.imagePreview?.src;

      this.$watch(
        () => this.progress,
        (value) => {
          if (value == 100 && this.cropAfterChange && !this.justCropped) {
            this.crop();
          }
        },
      );

      this.$el.addEventListener('livewire-upload-progress', (event) => {
        this.progress = event.detail.progress;
      });
    },

    destroy() {
      if (typeof this._morphOff === 'function') {
        this._morphOff();
      }
    },

    get processing() {
      return this.progress > 0 && this.progress < 100;
    },

    progressStyle() {
      return `--value:${this.progress}; --size:1.5rem; --thickness: 4px;`;
    },

    close() {
      this.$refs.maryCrop.close();
      this.cropper?.destroy();
    },

    change() {
      if (this.processing) {
        return;
      }

      this.$refs.file.click();
    },

    refreshImage() {
      this.progress = 1;
      this.justCropped = false;

      if (this.imagePreview?.src) {
        this.imagePreview.src = URL.createObjectURL(this.$refs.file.files[0]);
        this.imageCrop.src = this.imagePreview.src;
      }
    },

    crop() {
      this.$refs.maryCrop.showModal();
      this.cropper?.destroy();

      this.cropper = new Cropper(this.imageCrop, this.cropSetup());
    },

    // The crop setup JSON is server-rendered into a data attribute
    // by the component class; parsed lazily so a missing block
    // degrades to the built-in defaults instead of throwing.
    cropSetup() {
      try {
        return JSON.parse(this.$el.dataset.cropSetup || '{}');
      } catch (e) {
        return {};
      }
    },

    revert() {
      this.$wire.$removeUpload(this.modelName, this.file.split('livewire-file:').pop(), () => {
        this.imagePreview.src = this.originalImageUrl;
      });
    },

    async save() {
      this.$refs.maryCrop.close();

      this.progress = 1;
      this.justCropped = true;

      this.imagePreview.src = this.cropper.getCroppedCanvas().toDataURL();
      this.imageCrop.src = this.imagePreview.src;

      this.cropper.getCroppedCanvas().toBlob((blob) => {
        blob.name = this.$refs.file.files[0].name;
        this.$wire.upload(
          this.modelName,
          blob,
          () => { },
          () => { },
          (event) => { this.progress = event.detail.progress; },
        );
      }, this.$el.dataset.cropMimeType || 'image/png');
    },
  }));
}
