// @ts-check
/**
 * Escape a value for interpolation into HTML text context.
 *
 * Leaflet's `bindPopup(string)` parses the string as HTML, so any
 * user-controlled field must pass through here first (issue #1920). The
 * implementation mirrors `map-dashboard.js`'s `escapeHtml`: assigning to
 * `textContent` and reading `innerHTML` back escapes `&`, `<` and `>` without
 * double-escaping.
 *
 * @param {unknown} value
 * @returns {string}
 */
export function escapeHtml(value) {
  const div = document.createElement('div');
  div.textContent = value === null || value === undefined ? '' : String(value);

  return div.innerHTML;
}
