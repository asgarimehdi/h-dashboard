# h-dashboard — Browser Test Inventory (Playwright E2E)

> **Generated:** 2026-09-10 | **App:** Laravel 13 + Livewire 4 + MaryUI | **Language:** RTL Persian
> **Purpose:** Specification for building the Playwright E2E test suite. Do NOT modify code.

---

## 1. Complete Route/Page Inventory

| # | URL | Component | Auth Required | Permission | Description |
|---|-----|-----------|:---:|---|---|
| 1 | `/login` | `auth.login` | ❌ | — | Login page |
| 2 | `/register` | `auth.register` | ❌ | — | Registration (disabled in routes) |
| 3 | `/` | `index` | ✅ | — | Role selection / home |
| 4 | `/dashboard` | `dashboard` | ✅ | — | Main dashboard |
| 5 | `/select-context` | `select-context` | ✅ | — | Unit context selection |
| 6 | `/users` | `users.index` | ✅ | `manage_users` | User management list |
| 7 | `/users/create` | `users.create` | ✅ | `manage_users` | Create user |
| 8 | `/users/{user}/edit` | `users.edit` | ✅ | `manage_users` | Edit user |
| 9 | `/users/changepassword` | `auth.changepassword` | ✅ | — | Change own password |
| 10 | `/units` | `units.index` | ✅ | `organization` | Unit management |
| 11 | `/units/chart` | `units.chart` | ✅ | `organization` | Org chart tree |
| 12 | `/units/{id}/map` | `units.map` | ✅ | `organization` | Unit map (boundary) |
| 13 | `/hardware` | `hardware.index` | ✅ | `manage_hardware` | Hardware inventory |
| 14 | `/hardware/import` | `hardware.import-hardware` | ✅ | `manage_hardware` | Hardware Excel import |
| 15 | `/hardware/export` | `HardwareExportController` | ✅ | `manage_hardware` | Hardware Excel export |
| 16 | `/kargozini/persons` | `kargozini.person` | ✅ | `kargozini` | Personnel management |
| 17 | `/kargozini/persons/import` | `kargozini.import-persons` | ✅ | `kargozini` | Persons Excel import |
| 18 | `/kargozini/estekhdams` | `kargozini.estekhdam` | ✅ | `kargozini` | Employment types |
| 19 | `/kargozini/tahsils` | `kargozini.tahsil` | ✅ | `kargozini` | Education levels |
| 20 | `/kargozini/semats` | `kargozini.semat` | ✅ | `kargozini` | Job titles |
| 21 | `/kargozini/radifs` | `kargozini.radif` | ✅ | `kargozini` | Radif codes |
| 22 | `/hr-dashboard` | `hr.dashboard` | ✅ | `view_hr_dashboard` | HR analytics dashboard |
| 23 | `/hr/org-chart` | `hr.org-chart` | ✅ | `view_hr_dashboard` | HR org chart |
| 24 | `/tickets/new` | `tickets.create` | ✅ | `create_ticket` | Create ticket |
| 25 | `/tickets/inbox` | `tickets.inbox` | ✅ | `view_assigned_tickets` | Ticket inbox |
| 26 | `/monitoring` | `tickets.monitoring` | ✅ | `view_all_tickets` | Ticket monitoring |
| 27 | `/todo` | `todo.todo` | ✅ | `calendar` | Todo/calendar |
| 28 | `/maps/route` | `maps/route` | ✅ | `map` | Route map |
| 29 | `/maps/route2` | `maps/route2` | ✅ | `map` | Route map v2 |
| 30 | `/maps/county` | `maps/county` | ✅ | `map` | County map |
| 31 | `/maps/unit` | `maps/unit` | ✅ | `map` | Unit map |
| 32 | `/maps/interactive` | `maps/interactive` | ✅ | `map` | Interactive map |
| 33 | `/maps/point` | `maps/point` | ✅ | `map` | Point map |
| 34 | `/map` | `map.map-dashboard` | ✅ | `map` | GIS dashboard |
| 35 | `/it/wireless` | `it/wireless` | ✅ | `map` | Wireless networks |
| 36 | `/it/networks` | `it/networks` | ✅ | `map` | Network monitoring |
| 37 | `/users` (activity-log) | `activity-log.index` | ✅ | `manage_users` | Activity log |
| 38 | `/permissions` | `permissions/index` | ✅ | `manage_roles` | Permissions CRUD |
| 39 | `/roles` | `roles/index` | ✅ | `manage_roles` | Roles CRUD |
| 40 | `/search` | `search.index` | ✅ | — | Global search |
| 41 | `/reports/tickets` | `reports.advanced` | ✅ | — | Ticket reports |
| 42 | `/reports/units` | `reports.units` | ✅ | — | Unit reports |
| 43 | `/reports/todos` | `reports.todos` | ✅ | — | Todo reports |
| 44 | `/reports/persons` | `reports.persons` | ✅ | — | Personnel reports |
| 45 | `/reports/map-no-boundary` | `reports.map-no-boundary` | ✅ | — | Units without boundary |
| 46 | `/settings` | `settings.index` | ✅ | — | User settings |
| 47 | `/profile` | `profile.index` | ✅ | — | User profile |
| 48 | `/tools` | `tools.tools` | ✅ | — | Admin tools |
| 49 | `/docs/{page}` | `docs.user-guide` | ❌ | — | User documentation |

---

## 2. Module Inventory

| Module | Components | CRUD | Forms | Modals | Search | Pagination | Maps | Charts |
|--------|:----------:|:----:|:-----:|:------:|:------:|:----------:|:----:|:------:|
| **Auth** | 3 | — | 2 | — | — | — | — | — |
| **Users** | 1 | ✅ | 1 (inline) | — | ✅ | ✅ | — | — |
| **Roles** | 1 | ✅ | 1 (modal) | ✅ | ✅ | ✅ | — | — |
| **Permissions** | 1 | ✅ | 1 (modal) | ✅ | ✅ | ✅ | — | — |
| **Units** | 4 | ✅ | 1 (modal) | ✅ | ✅ | ✅ | ✅ | — |
| **Hardware** | 6 | ✅ | 2 (inline+modal) | 3 | ✅ | ✅ | — | — |
| **Persons** | 2 | ✅ | 1 (inline) | — | ✅ | ✅ | — | — |
| **Kargozini** | 5 | ✅ | 1 (modal each) | ✅ | ✅ | ✅ | — | — |
| **Tickets** | 3 | ✅ | 2 | 2+ | ✅ | ✅ | — | — |
| **Todo** | 1 | ✅ | 1 | ✅ | ✅ | ✅ | — | ✅ |
| **HR** | 2 | — | — | — | — | — | — | ✅ |
| **Maps** | 7 | — | — | — | — | — | ✅ | — |
| **IT** | 2 | — | — | — | — | — | — | ✅ |
| **Reports** | 5 | — | — | — | — | — | — | ✅ |
| **Dashboard** | 1 | — | — | — | — | — | — | ✅ |
| **Settings** | 1 | — | — | — | — | — | — | — |
| **Profile** | 1 | — | — | — | — | — | — | — |
| **Search** | 1 | — | — | — | ✅ | — | — | — |
| **Activity Log** | 1 | — | — | ✅ | ✅ | ✅ | — | — |
| **Tools** | 1 | — | — | — | — | — | — | — |

---

## 3. Form Inventory

### 3.1 Login (`/login`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| کد ملی (n_code) | text | ✅ | required, rate-limited 5/min |
| رمز عبور (password) | password | ✅ | required |
| مرا به خاطر بسپار (remember) | checkbox | ❌ | — |

### 3.2 Change Password (`/users/changepassword`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| رمز فعلی (currentPassword) | password | ✅ | required |
| رمز جدید (newPassword) | password | ✅ | required, min:8, different:current |
| تکرار رمز جدید (newPasswordConfirmation) | password | ✅ | required, same:new |

### 3.3 Users (inline form on `/users`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| نام/کد ملی پرسنل (person_search) | text (autocomplete) | ✅ | exists:persons,n_code |
| رمز عبور (password) | password | ✅ (create) | min:6 |
| نقش‌ها (role_ids) | multi-select | ✅ (edit) | exists:roles,id |
| دسترسی‌های مستقیم (user_permissions) | multi-select | ❌ | exists:permissions,name |
| واحدها (unit_ids) | tree picker modal | ❌ | — |

### 3.4 Hardware Create (inline form on `/hardware`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| کد ملی پرسنل | text (autocomplete) | ✅ | exists:persons,n_code (org-scoped) |
| نام دستگاه | text | ✅ | max:255 |
| نوع / OS / IP / MAC / CPU / RAM / HDD | text | ❌ | — |
| سوئیچ / پورت / VLAN | text | ❌ | — |
| مادربورد | text | ❌ | — |
| تاریخ نظافت | date | ❌ | — |
| فعال/غیرفعال | checkbox | ❌ | — |
| علامت | checkbox | ❌ | — |
| توضیحات | textarea | ❌ | — |

### 3.5 Hardware Edit (modal) — Same 19 fields as Create

### 3.6 Tickets Create (`/tickets/new`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| واحد گیرنده | text (autocomplete) | ✅ | exists:units,id, ≠ own unit |
| سطح فوریت | select (normal/urgent/high) | ❌ | default: normal |
| موضوع | text | ✅ | min:5, max:255 |
| شرح درخواست | textarea | ✅ | min:10 |
| وظیفه مرتبط | select (optional) | ❌ | — |
| پیوست‌ها | file upload (multi) | ❌ | mimes:jpg,png,pdf,zip,rar, max:5MB each, max:5 files |

### 3.7 Ticket Inbox — Completion Modal
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| نحوه تکمیل | radio (complete/forward) | ✅ | — |
| واحد جدید (if forwarding) | autocomplete | ✅ (forward) | exists:units,id |
| فایل پیوست | file upload | ❌ | — |

### 3.8 Todo (`/todo`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| عنوان | text | ✅ | — |
| توضیحات | textarea | ❌ | — |
| تاریخ شروع | Jalali date | ✅ | — |
| تاریخ پایان | Jalali date | ❌ | — |
| اولویت | select | ❌ | — |
| تکرار | select | ❌ | — |

### 3.9 Persons (`/kargozini/persons`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| کد ملی | text | ✅ | size:10, unique:persons |
| نام | text | ✅ | — |
| نام خانوادگی | text | ✅ | — |
| نوع اشتغال | select (FK) | ✅ | exists:estekhdams |
| سطح تحصیلات | select (FK) | ✅ | exists:tahsils |
| سمت | select (FK) | ✅ | exists:semats |
| ردیف | select (FK) | ❌ | exists:radifs |
| واحد | select (FK) | ✅ | exists:units |

### 3.10 Units (`/units`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| نام | text | ✅ | — |
| نوع واحد | select (FK) | ✅ | exists:unit_types |
| واحد مادر | select (conditional) | ✅ (non-ministry) | exists:units |
| استان | select (FK) | ✅ (ministry) | exists:regions |
| کد | text | ❌ | — |
| فعال | checkbox | ❌ | — |
| دریافت تیکت | checkbox | ❌ | — |

### 3.11 Roles (`/roles`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| نام (name) | text | ✅ | alpha_num:ascii, unique:roles |
| برچسب (label) | text | ✅ | — |
| دسترسی‌ها | multi-select | ❌ | — |

### 3.12 Permissions (`/permissions`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| نام (name) | text | ✅ | regex: ^[a-zA-Z0-9\s\-]+$, unique |
| برچسب (label) | text | ✅ | — |

### 3.13 Settings (`/settings`)
| Field | Type | Required | Validation |
|-------|------|:--------:|------------|
| نمایش نام | text | ❌ | — |
| زبان | select | ❌ | — |
| تم | select | ❌ | — |
| اعلان‌ها | toggle | ❌ | — |

### 3.14 Hardware Import (`/hardware/import`)
- Excel file upload (.xlsx)
- Preview with diff (new/update/unchanged rows)
- Column mapping
- Confirm import

### 3.15 Persons Import (`/kargozini/persons/import`)
- Excel file upload (.xlsx)
- Preview with diff
- Column mapping
- Confirm import

---

## 4. CRUD Inventory

### Users
| Operation | Action | UI Element | Expected Result |
|-----------|--------|------------|-----------------|
| **Create** | Click "+" button | `openFormForCreate` | Inline form opens |
| | Fill person_search + password + roles | | Fields populated |
| | Submit | `createUser` | Success toast, user appears in list |
| **Read** | Page load | | Paginated user list |
| | Search by name/n_code | | Filtered results |
| | Filter by status (active/inactive/all) | | Filtered results |
| **Update** | Click pencil icon | `edit(userId)` | Inline form opens with data |
| | Change fields | | Fields updated |
| | Submit | `updateUser` | Success toast, data updated |
| **Delete** | Click trash icon | `delete(user)` | Confirm dialog |
| | Confirm | | Warning toast, user soft-deleted |
| **Restore** | Click restore icon (for trashed) | `restore(userId)` | Confirm dialog |
| | Confirm | | Success toast, user restored |

### Hardware
| Operation | Action | UI Element | Expected Result |
|-----------|--------|------------|-----------------|
| **Create** | Click "+" | Inline form | Form opens below toolbar |
| | Search person + fill fields | | Person selected, fields filled |
| | Submit | `createHardware` | Toast, row appears in table |
| **Read** | Page load | | Paginated table with filters |
| | Sort columns | | Table re-sorted |
| | Filter by type/os/cpu/ram/etc | | Filtered results |
| **Update** | Click pencil (or expand row) | Modal opens | Edit form pre-filled |
| | Change fields | | Fields updated |
| | Submit | `updateHardware` | Toast, data updated |
| **Delete** | Click trash | Confirm dialog | Confirm/Cancel |
| | Confirm | `deleteHardware` | Toast, row removed |
| **Bulk** | Select checkboxes | | Checkboxes toggled |
| | Bulk mark/delete | | Bulk action toolbar |
| **Export** | Click export button | Download | Excel file downloaded |
| **Import** | Go to /hardware/import | Upload page | Upload → Preview → Confirm |

### Tickets
| Operation | Action | UI Element | Expected Result |
|-----------|--------|------------|-----------------|
| **Create** | Go to /tickets/new | Create form | Form with unit search |
| | Search unit + fill subject/content | | Fields populated |
| | Submit | `saveTicket` | Toast, redirected to inbox |
| **Read** | Inbox page | `tickets.inbox` | Paginated ticket list |
| | Click ticket row | Expand | Comments/replies shown |
| **Update** | Status change | Select dropdown | Status updated |
| | Forward | Completion modal | Forwarded to new unit |
| **Complete** | Click complete | Completion modal | Ticket marked complete |
| **Comment** | Type in comment box | Submit | Comment added |
| **React** | Click emoji | | Reaction added/removed |

### Units
| Operation | Action | UI Element | Expected Result |
|-----------|--------|------------|-----------------|
| **Create** | Click "+" | Modal opens | Create form |
| | Fill fields | | Fields populated |
| | Submit | `createUnit` | Toast, unit appears |
| **Read** | Page load | Tree view | Hierarchical unit list |
| | Search | | Filtered results |
| **Update** | Click edit | Modal opens | Edit form pre-filled |
| | Submit | `updateUnit` | Toast, data updated |
| **Delete** | Click delete | Confirm dialog | Confirm/Cancel |
| | Confirm | `deleteUnit` | Toast, unit removed |
| **Map** | Click map icon | Map page | Boundary editor |

### Persons (Kargozini)
| Operation | Action | UI Element | Expected Result |
|-----------|--------|------------|-----------------|
| **Create** | Click "+" | Inline form | Form opens |
| | Fill 8 fields | | All required fields filled |
| | Submit | `createPerson` | Toast, person appears |
| **Read** | Page load | | Paginated person list |
| | Search | | Filtered results |
| **Update** | Click edit | Inline form | Form pre-filled |
| | Submit | `updatePerson` | Toast, data updated |
| **Delete** | Click delete | Confirm | Person removed |
| **Import** | Go to import page | Upload | Excel import workflow |

### Roles
| Operation | Action | UI Element | Expected Result |
|-----------|--------|------------|-----------------|
| **Create** | Click "+" | Modal | Create form |
| | Fill name + label + permissions | | Fields populated |
| | Submit | `createRole` | Toast, role appears |
| **Update** | Click edit | Modal | Edit form pre-filled |
| | Submit | `updateRole` | Toast, data updated |
| **Delete** | Click delete | Confirm | Role removed |

### Permissions
| Operation | Action | UI Element | Expected Result |
|-----------|--------|------------|-----------------|
| **Create** | Click "+" | Modal | Create form |
| | Fill name + label | | Fields populated |
| | Submit | `createPermission` | Toast, permission appears |
| **Update** | Click edit | Modal | Edit form pre-filled |
| | Submit | `updatePermission` | Toast, data updated |
| **Delete** | Click delete | Confirm | Permission removed |

### Todos
| Operation | Action | UI Element | Expected Result |
|-----------|--------|------------|-----------------|
| **Create** | Click "+" or click date on calendar | Modal | Create form |
| | Fill title + dates | | Fields populated |
| | Submit | `createTodo` | Toast, todo appears on calendar |
| **Read** | Page load | Calendar view | Todos displayed on dates |
| | List view | | Paginated todo list |
| **Update** | Click todo | Modal | Edit form pre-filled |
| | Submit | `updateTodo` | Toast, data updated |
| **Complete** | Toggle checkbox | | Todo marked complete |
| **Delete** | Click delete | Confirm | Todo removed |
| **Drag** | Drag on calendar | | Date changed |

---

## 5. Search/Filter/Sort/Pagination Inventory

| Page | Search Field | Filters | Sort | Pagination |
|------|-------------|---------|------|:----------:|
| `/users` | Name/n_code | Status (active/inactive/all) | n_code | ✅ |
| `/hardware` | PC name/person | Type, OS, CPU, RAM, HDD, Shutdown, NetType, Mark, Person, Unit, Semat | 14 columns | ✅ |
| `/kargozini/persons` | Name/n_code | Unit, Semat, Tahsil, Estekhdam | Multiple | ✅ |
| `/kargozini/estekhdams` | Name | — | — | ✅ |
| `/kargozini/tahsils` | Name | — | — | ✅ |
| `/kargozini/semats` | Name | — | — | ✅ |
| `/kargozini/radifs` | Name | — | — | ✅ |
| `/tickets/inbox` | Subject/unit | Status (pending/accepted/completed/all), Unit | Latest | ✅ |
| `/monitoring` | — | Status, Date range | — | ✅ |
| `/todo` | Title | Date range, Status, Priority | — | ✅ |
| `/units` | Name | Type, Active status | — | ✅ |
| `/roles` | Name | — | — | ✅ |
| `/permissions` | Name | — | — | ✅ |
| `/activity-log` | Description | User, Date range | — | ✅ |
| `/reports/*` | — | Date range (Jalali), Unit, Type | — | ✅ |
| `/search` | Global search | — | — | — |

---

## 6. Authorization Inventory

| Module | View | Create | Edit | Delete | Permission |
|--------|:----:|:------:|:----:|:------:|------------|
| Users | admin, operator | admin | admin | admin | `manage_users` |
| Units | admin | admin | admin | admin | `organization` |
| Hardware | admin, operator | admin | admin | admin | `manage_hardware` |
| Persons | admin, operator | admin | admin | admin | `kargozini` |
| Tickets (create) | — | operator | — | — | `create_ticket` |
| Tickets (inbox) | operator | — | — | — | `view_assigned_tickets` |
| Tickets (monitoring) | admin | — | — | — | `view_all_tickets` |
| Todo | admin | admin | admin | admin | `calendar` |
| Maps | admin | — | — | — | `map` |
| HR Dashboard | admin | — | — | — | `view_hr_dashboard` |
| Roles | admin | admin | admin | admin | `manage_roles` |
| Permissions | admin | admin | admin | admin | `manage_roles` |
| Activity Log | admin | — | — | — | `manage_users` |
| Reports | admin | — | — | — | (authenticated) |
| Settings | all | — | — | — | (authenticated) |
| Profile | all | — | — | — | (authenticated) |

**Unauthorized access:** Guest → 302 redirect to `/login` for ALL protected routes.

---

## 7. Livewire-Specific Behaviors

| Behavior | Components | Test Priority |
|----------|-----------|:-------------:|
| `wire:model.live.debounce` search | Users, Hardware, Persons, Tickets, Todos | P0 |
| `wire:submit.prevent` form submission | All forms | P0 |
| `wire:click` modal open/close | Hardware edit, Roles, Permissions, Units, Ticket completion | P0 |
| `wire:confirm` delete confirmation | Users, Hardware, Units, Persons, Roles, Permissions, Todos | P0 |
| Loading states (`wire:loading`) | All form submits, ticket create, hardware create | P1 |
| Toast notifications (success/error/warning) | All CRUD operations | P1 |
| Inline form toggle (`formOpen`) | Users, Hardware, Persons | P0 |
| Autocomplete search (person/unit) | Users, Hardware, Tickets | P0 |
| File upload with preview | Ticket create, Hardware import, Persons import | P1 |
| Dependent selects | Units (type → parent), Tickets (unit search) | P1 |
| Pagination (`wire:model.live`) | All paginated lists | P0 |
| Sort columns | Hardware, Users | P1 |
| `wire:model` expandable rows | Hardware (audit trail), Tickets (comments) | P1 |
| Real-time validation | Login (n_code check), Hardware (person search) | P1 |
| Alpine.js integration | Maps (Leaflet), Charts (Highcharts), Date pickers | P2 |
| Jalali date picker | Todo, Reports, Activity Log, Tickets | P1 |
| Theme toggle | All pages (dark/light mode) | P2 |
| Notification bell | All pages (header) | P1 |
| Help modal | All pages with `x-help:button` | P2 |

---

## 8. Negative Test Cases

### Auth
- Login with empty fields → validation errors
- Login with wrong n_code → error toast
- Login with wrong password → error toast (constant-time, no user enumeration)
- Login 6 times rapidly → rate limited (429)
- Access protected page without auth → 302 to /login
- Change password with wrong current password → error
- Change password with short new password → validation
- Change password with same-as-current → validation

### Users
- Create user with existing n_code → "already registered" error
- Create user with non-existent n_code → "not found in system" error
- Create user with short password → validation
- Edit user without selecting role → validation
- Delete self → should be prevented (query excludes `auth()->id()`)

### Hardware
- Create hardware with non-existent person → validation
- Create hardware without pc_name → validation
- Create hardware for person in inaccessible unit → 403
- Export empty result → empty Excel
- Import invalid file type → error
- Import file with missing required columns → error preview

### Tickets
- Create ticket to own unit → validation error
- Create ticket without subject → validation
- Create ticket with short content (<10 chars) → validation
- Upload oversized file (>5MB) → validation
- Upload invalid file type → validation
- Upload more than 5 files → validation

### Units
- Create unit with duplicate name → depends on rules
- Delete unit with children → should be prevented or warned
- Delete unit with persons → FK constraint error

### Persons
- Create person with non-10-digit n_code → validation
- Create person with duplicate n_code → unique validation
- Person import with wrong columns → error preview

### Todos
- Create todo without title → validation
- Create todo without start date → validation
- Create todo with end date before start date → validation

### General
- Submit form twice rapidly (double-submit) → should be idempotent
- Navigate during form fill → confirmation or data loss
- Resize browser → responsive layout should adapt
- Browser back button → should not break state

---

## 9. Responsive Test Cases

| Page | Desktop | Tablet | Mobile |
|------|:-------:|:------:|:------:|
| `/login` | Full layout | Adapted | Stacked form |
| `/dashboard` | Grid stats + charts | 2-col grid | Single column |
| `/hardware` | Full table + filters | Collapsed columns | Card view |
| `/tickets/inbox` | Full table | Collapsed columns | Card view |
| `/todo` | Calendar + list | Calendar only | List only |
| `/maps/*` | Full map + sidebar | Full map | Full map, bottom sheet |
| `/units` | Tree + detail | Tree only | Collapsed tree |
| `/reports/*` | Charts + tables | Charts only | Tables only |
| Sidebar | Expanded | Collapsed icons | Hidden (hamburger) |
| Modals | Centered | Centered | Full-screen |

---

## 10. Priority Classification

### P0 — Critical (Must Have)
1. Login flow (success + failure + rate limit)
2. Logout
3. Hardware list (search, filter, sort, paginate)
4. Hardware create (inline form + person search)
5. Hardware edit (modal)
6. Hardware delete (confirm)
7. Ticket create (unit search + file upload)
8. Ticket inbox (list + expand)
9. Ticket completion/forward
10. User list (search, filter, paginate)
11. User create (inline form)
12. User edit
13. Todo create (Jalali date)
14. Todo calendar view
15. Unit context selection
16. Sidebar navigation
17. Unauthorized access → 302 redirect
18. Pagination across all lists
19. Search across all searchable lists

### P1 — Important
20. Hardware bulk mark/delete
21. Hardware export/import
22. Person create/edit/delete
23. Person import
24. Role create/edit/delete
25. Permission create/edit/delete
26. Ticket comments
27. Ticket monitoring page
28. Activity log (search + expand)
29. Reports (ticket/unit/todo/person)
30. Change password
31. Profile page
32. Settings page
33. Toast notifications
34. Loading states
35. Jalali date pickers
36. Help modals

### P2 — Secondary
37. HR Dashboard charts
38. HR Org Chart
39. GIS Map Dashboard
40. All map pages (route, county, unit, interactive, point)
41. IT Networks/Wireless charts
42. Global search
43. Theme toggle (dark/light)
44. Notification bell
45. Org chart tree view

### P3 — Low Risk
46. Glowing card effect
47. Responsive layouts
48. OPcache GUI (local only)
49. Tools page
50. Docs page
51. Unit map boundary editor
52. Kargozini sub-pages (estekhdams, tahsils, semats, radifs)

---

## 11. Recommended Playwright Test Structure

```
tests/Browser/
├── auth/
│   ├── LoginTest.php                    # P0: login success, failure, rate limit, redirect
│   ├── LogoutTest.php                   # P0: logout flow
│   └── ChangePasswordTest.php           # P1: change password
│
├── navigation/
│   ├── SidebarTest.php                  # P0: menu links, active state, permissions
│   ├── UnauthorizedAccessTest.php       # P0: 302 redirect for all protected routes
│   └── ResponsiveLayoutTest.php         # P3: mobile/tablet/desktop layouts
│
├── users/
│   ├── UserListTest.php                 # P0: search, filter, paginate
│   ├── UserCreateTest.php               # P0: inline form, person search, validation
│   ├── UserEditTest.php                 # P0: edit form, role selection
│   └── UserDeleteTest.php               # P0: delete confirm, restore
│
├── hardware/
│   ├── HardwareListTest.php             # P0: search, 11 filters, sort, paginate
│   ├── HardwareCreateTest.php           # P0: inline form, person search, validation
│   ├── HardwareEditTest.php             # P0: modal form, validation
│   ├── HardwareDeleteTest.php           # P0: single + bulk delete
│   ├── HardwareBulkMarkTest.php         # P1: bulk mark operations
│   ├── HardwareExportTest.php           # P1: Excel export download
│   └── HardwareImportTest.php           # P1: Excel import workflow
│
├── tickets/
│   ├── TicketCreateTest.php             # P0: unit search, file upload, validation
│   ├── TicketInboxTest.php              # P0: list, search, filter, expand
│   ├── TicketCompleteTest.php           # P0: complete/forward flow
│   ├── TicketCommentsTest.php           # P1: add comment, reactions
│   └── TicketMonitoringTest.php         # P1: monitoring dashboard
│
├── units/
│   ├── UnitListTest.php                 # P0: tree view, search
│   ├── UnitCreateTest.php               # P1: modal form, type dependencies
│   ├── UnitEditTest.php                 # P1: edit modal
│   ├── UnitDeleteTest.php               # P1: delete confirm
│   └── UnitMapTest.php                  # P2: boundary editor
│
├── todo/
│   ├── TodoCreateTest.php               # P0: Jalali date, validation
│   ├── TodoCalendarTest.php             # P0: calendar view, drag-drop
│   ├── TodoListTest.php                 # P1: list view, search, filter
│   └── TodoCompleteTest.php             # P1: toggle complete
│
├── kargozini/
│   ├── PersonListTest.php               # P1: search, filter, paginate
│   ├── PersonCreateTest.php             # P1: 8 fields, validation
│   ├── PersonEditTest.php               # P1: edit form
│   ├── PersonDeleteTest.php             # P1: delete confirm
│   └── PersonImportTest.php             # P1: Excel import workflow
│
├── roles-permissions/
│   ├── RoleCrudTest.php                 # P1: create, edit, delete, assign permissions
│   └── PermissionCrudTest.php           # P1: create, edit, delete
│
├── reports/
│   ├── TicketReportTest.php             # P2: filters, date range, charts
│   ├── UnitReportTest.php               # P2: stats, charts
│   ├── TodoReportTest.php               # P2: stats, charts
│   └── PersonReportTest.php             # P2: stats, charts
│
├── dashboard/
│   ├── DashboardTest.php                # P1: stats cards, charts render
│   └── GlobalSearchTest.php             # P2: search results
│
├── maps/
│   ├── GisDashboardTest.php             # P2: map loads, layers
│   └── UnitMapTest.php                  # P2: boundary display
│
└── settings/
    ├── SettingsTest.php                 # P2: save preferences
    └── ProfileTest.php                  # P2: profile display
```

---

## 12. Summary Statistics

| Metric | Count |
|--------|:-----:|
| Total routes | 49 |
| Livewire components | 62 |
| CRUD modules | 10 |
| Forms | 15 |
| Modals | 12+ |
| Searchable lists | 16 |
| Paginated lists | 14 |
| Map components | 7 |
| Chart components | 7 |
| P0 test scenarios | 19 |
| P1 test scenarios | 17 |
| P2 test scenarios | 10 |
| P3 test scenarios | 6 |
| **Total test scenarios** | **52** |
| **Proposed test files** | **35** |

---

*This document is the specification for building the Playwright E2E test suite. Do NOT implement tests from this document directly — use it as the source of truth for test planning.*
