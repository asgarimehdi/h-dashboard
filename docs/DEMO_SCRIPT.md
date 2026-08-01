# Health Dashboard Demo Video Script (فیلم دمو داشبورد سلامت)

## Overview
This document provides a comprehensive script for recording a demo video of the Health Dashboard (داشبورد سلامت) application. The demo should showcase all major features in Persian/Farsi with English subtitles.

---

## Demo Environment Setup
- **Application URL**: http://localhost:8000 (or deployed URL)
- **Test Credentials**: 
  - Admin: `admin@health.gov.ir` / `password`
  - Operator: `operator@health.gov.ir` / `password`
- **Resolution**: 1920x1080 minimum
- **Language**: Persian (RTL) interface with English subtitles
- **Duration Target**: 8-12 minutes total

---

## Demo Script Structure

### 1. Opening (30 seconds)
**Scene**: Login screen → Dashboard overview

**Narration (Persian)**:
> "سلام. به دموی داشبورد سلامت خوش آمدید. این سیستم برای مدیریت تجهیز شده به امکانات کامل مدیریت سخت‌افزار، پرسنل، واحدها، تیکت‌ها و گزارش‌گیری برای مراکز درمانی و بیمارستانی است."

**English Subtitle**: "Welcome to the Health Dashboard demo. This system provides comprehensive hardware, personnel, unit, ticket, and reporting management for healthcare centers and hospitals."

**Actions**:
- Open login page
- Show RTL Persian interface
- Login as admin
- Show dashboard with stats cards

---

### 2. Dashboard Overview (60 seconds)
**Scene**: Dashboard page with stats cards, charts, recent activity

**Narration (Persian)**:
> "داشبورد اصلی نمای کلی از وضعیت سیستم را نمایش می‌دهد. کارت‌های آمار شامل تعداد کاربران، پرسنل، واحدها، تیکت‌های باز، و وسایل سخت‌افزاری است. نمودارهای تیکت‌ها و فعالیت‌های اخیر برای پایش سریع طراحی شده‌اند."

**English Subtitle**: "The main dashboard provides a system overview with stats cards for users, personnel, units, open tickets, and hardware devices. Charts for tickets and recent activity enable quick monitoring."

**Actions**:
- Scroll through stats cards
- Hover over ticket chart
- Show recent activity feed
- Demonstrate RTL layout

---

### 3. Personnel Management (Kargozini) (120 seconds)
**Scene**: `/kargozini/persons` - Personnel list with filters

**Narration (Persian)**:
> "مدیریت پرسنل یا کارگزینی قلب سیستم است. می‌توانید پرسنل را بر اساس واحد، سمت، تحصیلات، نوع استخدام و ردیف فیلتر کنید. جستجوی پیشرفته با Норمال‌سازی متن فارسی پشتیبانی می‌شود."

**English Subtitle**: "Personnel management (Kargozini) is the core of the system. Filter by unit, position, education, employment type, and rank. Advanced search with Persian text normalization."

**Actions**:
- Show personnel table with columns
- Demonstrate filters: Unit, Semat, Tahsil, Estekhdam, Radif
- Show search with Persian text (test Arabic ی/ک normalization)
- Click "Create" → show modal form
- Fill form: n_code, name, unit, semat, etc.
- Save and show in table
- Demonstrate bulk actions if available
- Show Import Persons feature (Excel/CSV upload)

**Key Features to Show**:
- RTL form with MaryUI components
- Persian date pickers
- Validation with Persian messages
- Import/Export functionality

---

### 4. Unit Management & Org Chart (90 seconds)
**Scene**: `/units` - Unit hierarchy and management

**Narration (Persian)**:
> "مدیریت واحدها ساختار سازمانی را به صورت درختی نمایش می‌دهد. واحدها می‌توانند والد و فرزند داشته باشند. این سلسله مراتب برای تعیین محدوده دسترسی کاربران حیاتی است."

**English Subtitle**: "Unit management displays the organizational structure as a tree hierarchy. Units can have parent-child relationships. This hierarchy is critical for determining user access scopes."

**Actions**:
- Show unit tree view
- Expand/collapse nodes
- Create new unit (name, parent, type, region)
- Show unit map integration
- Demonstrate boundary/geometry features if available
- Show region and unit type management

---

### 5. Map Views (GIS Features) (120 seconds)
**Scenes**: 
- `/maps/unit` - Units on map
- `/maps/no-boundary` - Units without boundaries

**Narration (Persian)**:
> "نمایش نقشه قابلیت‌های GIS را فراهم می‌کند. واحدها روی نقشه با مرزی‌ها و مختصات نمایش داده می‌شوند. می‌توانید واحدهای بدون مرزی را شناسایی و گزارش بگیرید. جستجوی فضایی و محاسبه فاصله پشتیبانی می‌شود."

**English Subtitle**: "Map views provide GIS capabilities. Units display on the map with boundaries and coordinates. Identify units without boundaries and generate reports. Spatial search and distance calculation are supported."

**Actions**:
- Show map with unit markers
- Click unit → show info popup
- Show boundary polygons
- Switch to "Units Without Boundary" report
- Demonstrate spatial queries (nearby, within bounds)
- Show layer controls

---

### 6. Ticket System Workflow (120 seconds)
**Scenes**: 
- `/tickets/create` - Create ticket
- `/tickets/inbox` - My tickets
- `/tickets/monitoring` - Monitoring view

**Narration (Persian)**:
> "سیستم تیکت‌دهی گردش کار کامل پشتیبانی، پذیرش و تکمیل را مدیریت می‌کند. اولویت‌ها: فوری، بالا، متوسط، عادی، پایین. تیکت‌ها به واحدها و کاربران انتساب داده می‌شوند."

**English Subtitle**: "The ticket system manages the complete support workflow: creation, acceptance, and completion. Priorities: Urgent, High, Medium, Normal, Low. Tickets are assigned to units and users."

**Actions**:
- Create new ticket (subject, content, priority, unit)
- Show ticket list with filters (status, priority, assigned to me)
- Open ticket detail
- Demonstrate: Assign → Accept → Complete workflow
- Show activities/timeline
- Show monitoring view for managers

---

### 7. Hardware Inventory (120 seconds)
**Scenes**:
- `/hardware` - Hardware list with filters
- `/hardware/import` - Excel Import

**Narration (Persian)**:
> "مدیریت انبار سخت‌افزار با فیلترهای پیشرفته و عملیات دسته‌جمعی. دستگاه‌ها به پرسنل و واحدها متصل هستند. импорт اکسل با پیش‌نمایش و مقایسه کلید (نام دستگاه یا MAC) پشتیبانی می‌شود."

**English Subtitle**: "Hardware inventory management with advanced filters and bulk operations. Devices link to personnel and units. Excel import with preview and match-key comparison (device name or MAC)."

**Actions - Hardware List**:
- Show table with columns (PC Name, Type, OS, IP, MAC, CPU, RAM, HDD, Status)
- Demonstrate quick filters (Laptops, Servers, SSD, 16GB+, Shutdown)
- Show advanced filter panel
- Bulk select → Mark/Unmark → Bulk Delete
- Column visibility toggle
- Mobile card view (resize browser)

**Actions - Import** (`/hardware/import`):
- Click Import
- Upload sample Excel file
- Show preview with status badges (Create, Update, Unchanged, Error)
- Change compare key (PC Name / MAC / Both)
- Confirm import
- Show success toast

---

### 8. Reports (90 seconds)
**Scenes**: `/reports` - Index, Units, Persons, Todos, Tickets, Advanced

**Narration (Persian)**:
> "بخش گزارش‌ها شامل گزارش واحدها، پرسنل، کارها، تیکت‌ها و گزارش پیشرفته است. نمودارها با агрегаشن SQL برای عملکرد بهینه ساخته شده‌اند. همه گزارش‌ها محدوده سازمانی کاربر را احترام می‌گذارند."

**English Subtitle**: "Reports include Units, Personnel, Todos, Tickets, and Advanced reports. Charts use SQL aggregation for optimal performance. All reports respect user's organizational scope."

**Actions**:
- Units report: Chart by type, table with stats
- Persons report: Charts by education, position, employment, unit
- Todos report: By unit, completion status
- Tickets report: By status, priority, unit, assignee
- Advanced report: Combined views
- Show export buttons

---

### 9. IT Monitoring (Zabbix Integration) (60 seconds)
**Scene**: `/it-monitoring` or `/zabbix`

**Narration (Persian)**:
> "یکپارچه‌سازی با زابیکس برای نظارت بر ترافیک شبکه و مقادیر آخرین چک‌های سرورها. داشبورد ترافیک و مقادیر لحظه‌ای نمایش داده می‌شود."

**English Subtitle**: "Zabbix integration for network traffic monitoring and latest server check values. Traffic dashboard and real-time values display."

**Actions**:
- Show traffic charts
- Show multi-latest values table
- Demonstrate auto-refresh

---

### 10. Admin Settings (60 seconds)
**Scenes**: `/settings`, `/roles`, `/permissions`, `/users`, `/activity-log`

**Narration (Persian)**:
> "تنظیمات ادمین شامل مدیریت نقش‌ها، مجوزها، کاربران، و لاگ فعالیت است. سیستم از Spatie Permission با محدوده سازمانی استفاده می‌کند. مجوز `manage_hardware` برای دسترسی به سخت‌افزارها لازم است."

**English Subtitle**: "Admin settings cover roles, permissions, users, and activity logs. Uses Spatie Permission with organizational scope. The `manage_hardware` permission is required for hardware access."

**Actions**:
- Show roles list with permissions
- Create/edit role
- Show permissions matrix
- User management
- Activity log with filters

---

### 11. In-App Help System (30 seconds)
**Scene**: Any page with help button

**Narration (Persian)**:
> "سیستم راهنمای دراپلیکیشن با دکمه کمک در هر صفحه، محتوای مرتبط به فارسی نمایش می‌دهد. ۱۴ بخش راهنما در دسترس است."

**English Subtitle**: "The in-app help system shows contextual Persian documentation via help buttons on every page. 14 help sections available."

**Actions**:
- Click help button on hardware page
- Show modal with tabs
- Navigate between sections

---

### 12. API & AI Endpoints Demo (60 seconds)
**Scene**: Terminal / Postman / API Docs

**Narration (Persian)**:
> "API کامل RESTful با احراز هویت Sanctum. تمام اندپوینت‌ها محدوده سازمانی را رعایت می‌کنند. اندپوینت AI در `/api/ai/hardware` برای چت با دستیار سخت‌افزاری."

**English Subtitle**: "Complete RESTful API with Sanctum authentication. All endpoints respect organizational scope. AI endpoint at `/api/ai/hardware` for hardware assistant chat."

**Actions** (Terminal with curl):
```bash
# Get hardware list
curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/hardware

# Get stats
curl -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/hardware/stats

# AI Chat
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"message": "Show shutdown laptops"}' \
  http://localhost:8000/api/ai/hardware
```

---

### 13. Persian RTL & Localization Features (30 seconds)
**Scene**: Various pages showing RTL, Persian dates, normalization

**Narration (Persian)**:
> "پشتیبانی کامل RTL، تقویم شمسی، و Норمال‌سازی متن فارسی (تبدیل ی/ک عربی به فارسی، حذف ZWNJ/ZWJ)."

**English Subtitle**: "Full RTL support, Persian (Jalali) calendar, and Persian text normalization (Arabic ی/ک to Persian, ZWNJ/ZWJ removal)."

**Actions**:
- Show RTL layout
- Persian date picker
- Search with Arabic chars → normalized to Persian
- Show normalization command: `php artisan normalize:persian-text`

---

### 14. Closing (30 seconds)
**Scene**: Dashboard → Logout → Project links

**Narration (Persian)**:
> "تشکر از تماشا. داشبورد سلامت با Laravel ۱۳، Livewire ۴، و MaryUI ساخته شده. مستندات کامل در docs/user-guide و کد در GitHub موجود است."

**English Subtitle**: "Thank you for watching. Health Dashboard built with Laravel 13, Livewire 4, and MaryUI. Full documentation in docs/user-guide and source code on GitHub."

**Actions**:
- Show GitHub repo link
- Show docs link
- Show tech stack badges
- Fade to black with project name

---

## Recording Checklist

### Pre-Recording
- [ ] Set up clean demo database with sample data
- [ ] Configure test users (admin, operator, viewer)
- [ ] Prepare sample Excel files for import demos
- [ ] Test all features work correctly
- [ ] Set screen resolution to 1920x1080
- [ ] Configure OBS/screen recorder
- [ ] Test microphone and Persian narration

### During Recording
- [ ] Record in segments (easier to redo)
- [ ] Keep mouse movements smooth and deliberate
- [ ] Pause 2 seconds before/after each action
- [ ] Show loading states and transitions
- [ ] Demonstrate error states (validation, 403, etc.)
- [ ] Use keyboard shortcuts where natural

### Post-Recording
- [ ] Edit segments together
- [ ] Add Persian narration audio
- [ ] Add English subtitles
- [ ] Add zoom/pan on key UI elements
- [ ] Add chapter markers
- [ ] Export 1080p MP4
- [ ] Upload to hosting (YouTube, GitHub Releases, etc.)
- [ ] Update README with video link

---

## Sample Data Requirements

### Units (Hierarchical)
```
Ministry of Health
├── Tehran Province
│   ├── District 1 Health Center
│   │   ├── Clinic A
│   │   └── Clinic B
│   └── District 2 Health Center
└── Isfahan Province
    └── Central Health Center
```

### Personnel (20+ records)
- Various units, semats (physician, nurse, admin, IT), tahsils, estekhdams, radifs

### Hardware (50+ records)
- Types: desktop, laptop, server, printer, scanner
- OS: Windows 10/11, Linux, macOS
- Mix of shutdown/active, marked/unmarked
- Various CPU, RAM, HDD/SSD configs

### Tickets (20+ records)
- Various statuses: open, accepted, in_progress, completed, closed
- Various priorities
- Assigned to different users/units

### Todos (15+ records)
- Mix of completed/pending
- Different units
- With start/end dates

---

## Technical Specifications

| Parameter | Value |
|-----------|-------|
| Resolution | 1920x1080 (1080p) |
| Frame Rate | 30 fps |
| Format | MP4 (H.264) |
| Audio | Stereo, 44.1 kHz |
| Narration | Persian (Farsi) |
| Subtitles | English (SRT embedded or separate) |
| Duration | 8-12 minutes |
| Chapters | 14 sections as above |

---

## Distribution

- **Primary**: GitHub Releases / Repository README
- **Secondary**: YouTube (unlisted or public)
- **Documentation**: Embed in docs/user-guide/00-introduction.md
- **Thumbnail**: Dashboard screenshot with "Health Dashboard Demo" text

---

## Notes for Narrator

1. **Speak clearly and at moderate pace** - Persian narration with technical terms
2. **Use technical Persian terms** (یکپارچه‌سازی, محدوده سازمانی, نرمال‌سازی) but keep accessible
3. **Pause after each feature demo** - Give viewers time to see the UI
4. **Mention keyboard shortcuts** where helpful (Ctrl+K for search, etc.)
5. **Highlight unique features**: Organizational scope, Persian normalization, AI assistant, Excel import preview
6. **Reference documentation** - "مستندات کامل در راهنمای کاربری موجود است"

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-07-30 | Initial demo script based on issue #78 |

---

*Generated for Health Dashboard (داشبورد سلامت) - Laravel 13 + Livewire 4 + MaryUI*