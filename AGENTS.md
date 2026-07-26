# Health Dashboard (داشبورد سلامت) - Documentation

This document serves as the authoritative reference for the Health Dashboard project. It describes the system architecture, domain models, and the AI integration layer.

## 🏗️ Project Overview
A comprehensive organizational health and HR management system with a Persian (Farsi) interface. The system integrates personnel records, organizational hierarchy, IT infrastructure monitoring, and a ticket-based task management system.

### Tech Stack
- **Backend:** Laravel 13 (PHP 8.4)
- **Frontend:** Livewire 4, Tailwind CSS 4, maryUI 2.8, DaisyUI 5
- **Auth & Access:** Laravel Sanctum (API), Spatie Laravel Permission (Roles/Permissions)
- **GIS:** Leaflet.js for interactive mapping of units and boundaries.
- **Monitoring:** Zabbix API integration for network traffic and hardware health.
- **AI:** Custom Agent framework using `openai-php/client` (compatible with OpenAI and other providers).

## 🗄️ Data Model & Domain

### Core Entities
- **Person (پرسنل):** The central entity. Linked to:
  - `Semat` (Job Title/Position)
  - `Tahsil` (Education)
  - `Radif` (Rank/Grade)
  - `Estekhdam` (Employment Type)
  - `Unit` (Current organizational unit)
- **Unit (واحد سازمانی):** Hierarchical structure (parent/child) with GIS boundaries.
- **Hardware (سخت‌افزار):** Devices (PC, Laptop, etc.) linked to a `Person` via `n_code`.
- **Ticket (تیکت):** Support and task requests. Workflow: `created` → `forwarded` → `accepted` → `completed/rejected`.
- **Todo (وظیفه):** Simple task tracking for units.

### Key Relationships
- `Hardware` $\rightarrow$ `Person` (via `n_code`)
- `Person` $\rightarrow$ `Unit` (via `u_id`)
- `User` $\rightarrow$ `Person` (via `n_code`)
- `Unit` $\rightarrow$ `Unit` (Self-referential hierarchy via `parent_id`)

## 🤖 AI Integration (Agentic Layer)

The system implements a custom Agent pattern instead of a generic chat, allowing the AI to interact with the database through specialized tools.

### Agent Architecture
- **`App\Ai\Agent`**: Base class handling the LLM loop, tool registration, and function calling.
- **`App\Ai\Tools\Tool`**: Abstract base class for all functional capabilities.
- **`HardwareAgent`**: A specialized agent for hardware inventory management.

### Hardware Agent Capabilities
The `HardwareAgent` uses the following tools to provide a natural language interface to the hardware database:
- `search_hardware`: Deep search across all hardware specs and owner names.
- `hardware_stats`: Generates aggregate reports (total count, distribution by OS/Type).
- `person_hardware`: Lists all devices belonging to a specific person.
- `update_hardware`: Allows updating device specs via chat.

### AI Workflow
`User Input` $\rightarrow$ `Agent` $\rightarrow$ `Tool Execution (Eloquent Query)` $\rightarrow$ `LLM Synthesis` $\rightarrow$ `Final Response`

## 🛠️ Implementation Details

### Access Control
- **Functional:** Spatie Permissions (e.g., `manage_hardware`, `organization`).
- **Data Scope:** `HasOrganizationalScope` trait and `AccessService` use recursive CTEs to ensure users only see data from their own unit and descendants.

### Infrastructure
- **API:** Sanctum-protected endpoints for a Flutter mobile application.
- **Monitoring:** `ZabbixService` handles real-time network traffic data fetching.

## 🚀 Development Guidelines
- **UI Components:** Use `maryUI` for consistent layout and form elements.
- **Bidi Support:** Always ensure `dir="rtl"` and Persian labels in views.
- **Performance:** Use `Spatie` roles for quick access checks and `AccessService` for data-level security.
