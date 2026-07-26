# UX Improvement Suggestions for Hardware Module

These are suggestions only - **DO NOT IMPLEMENT YET**. Create GitHub issues from these if you agree.

---

## 1. AI Agent UX Improvements

### 1.1 Markdown Rendering
**Current:** Uses `nl2br(e($msg['content']))` in `hardware/ai-chat.blade.php`
**Issue:** AI returns structured data (tables, lists) but it renders as plain text.
**Fix:** Add a Markdown renderer (e.g., `league/commonmark` or frontend `marked.js`) to display proper tables, bold text, code blocks.

### 1.2 Contextual Action Buttons
**Current:** Static buttons at start: "لیست کامپیوترها", "آمار کلی", "خاموش‌ها"
**Issue:** Buttons don't adapt to conversation context.
**Fix:** Show dynamic buttons based on AI response:
- If device found → "ویرایش این دستگاه" (opens edit modal)
- If multiple devices → "فیلتر جدول" (applies filter to main table)
- If stats shown → "نمایش نمودار"

### 1.3 Direct Navigation / Deep Linking
**Current:** AI only returns text responses.
**Issue:** User asks "لیست لپ‌تاپ‌ها" → gets text → must manually filter table.
**Fix:** AI returns special action objects that trigger Livewire events:
```json
{
  "action": "filter_table",
  "params": { "type": "laptop" }
}
```
Frontend catches this and updates the main inventory table automatically.

### 1.4 Streaming Response
**Current:** Full response waits until complete.
**Issue:** Long responses feel slow.
**Fix:** Implement streaming with `stream: true` in Agent, update UI token-by-token.

---

## 2. Inventory Table UX

### 2.1 Quick-Filter Presets
**Current:** Only text inputs for each filter.
**Fix:** Add clickable preset chips above filters:
- `SSD Only` → sets `filterHdd = "SSD"`
- `16GB+ RAM` → sets `filterRam = "16384"` (or range)
- `Laptops` → sets `filterType = "laptop"`
- `Needs Cleaning` → `clean_at < today`

### 2.2 Bulk Actions
**Current:** Only single-row edit/delete.
**Fix:** Add checkbox column + bulk action toolbar:
- Mark/Unmark selected
- Change owner (n_code) for selected
- Export selected to CSV

### 2.3 Visual Status Badges
**Current:** Only background color for `mark` column.
**Fix:** Add status badge column combining:
- `shutdown` + `mark` + `clean_at` → "Operational" / "Maintenance Needed" / "Decommissioned"
- Color-coded badges (green/yellow/red)

### 2.4 Column Visibility Toggle
**Current:** Fixed columns, responsive hiding via Tailwind classes.
**Fix:** Add "Columns" dropdown to show/hide: MAC, Switch, Port, VLAN, Motherboard, Clean Date.

---

## 3. Data Quality & Forms

### 3.1 Input Masking
**Current:** Plain text inputs for IP/MAC.
**Fix:** Add input masks:
- IP: `xxx.xxx.xxx.xxx` (with validation)
- MAC: `XX:XX:XX:XX:XX:XX` (auto-format)

### 3.2 Real-time n_code Validation
**Current:** Validation only on submit.
**Fix:** As user types `n_code`, live-search `persons` table and show:
- ✅ Found: "محمد رضایی - مرکز فناوری اطلاعات"
- ❌ Not found: "کد ملی یافت نشد"

### 3.3 Duplicate Detection
**Fix:** Warn if `pc_name` or `mac` already exists in DB during create/edit.

---

## 4. Search Enhancement

### 4.1 Fuzzy/Persian-Aware Search
**Current:** Exact `LIKE %query%` matching.
**Issue:** Persian typos (ی/ي, ک/ك, spacing) break search.
**Fix:** Normalize both query and DB columns:
- Replace `ي`→`ی`, `ك`→`ک`
- Remove ZWNJ, extra spaces
- Or use Meilisearch with Persian analyzer

### 4.2 Search Suggestions / Autocomplete
**Fix:** As user types in general search, show dropdown with:
- Matching PC names
- Matching Persons
- Matching IPs
- Recent searches

---

## 5. AI Agent Missing Capabilities

### 5.1 Create Hardware via Chat
**Missing:** Cannot say "دستگاه جدید برای آقای محمدی بساز"
**Fix:** Add `CreateHardwareTool` with required fields validation.

### 5.2 Delete Hardware via Chat
**Missing:** Cannot delete via AI.
**Fix:** Add `DeleteHardwareTool` with confirmation requirement.

### 5.3 Export / Report Generation
**Missing:** Cannot say "خروجی اکسل لپ‌تاپ‌ها رو بده"
**Fix:** Add `ExportHardwareTool` returning CSV/Excel download link.

---

## 6. Mobile/Responsive

### 6.1 Hardware Page on Mobile
**Issue:** Table with 15+ columns is unusable on mobile.
**Fix:** Card-based layout for mobile (< 768px) showing key fields, expand for details.

---

## Priority Recommendation

**High Impact / Low Effort:**
1. Markdown rendering in AI chat
2. Quick-filter preset chips
3. Input masking for IP/MAC
4. Real-time n_code validation

**High Impact / Medium Effort:**
1. Direct navigation (AI → Table filter)
2. Bulk actions
3. Fuzzy Persian search

**Nice to Have:**
1. Streaming responses
2. Export via AI
4. Mobile card layout

---

Create GitHub issues for the ones you want to pursue.