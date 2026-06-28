---
name: maryui-frontend
description: Expert in Laravel Livewire development using maryUI, daisyUI, and Tailwind CSS – for rapid, reactive UI building.
triggers:
  - "maryui"
  - "livewire table"
  - "daisyui form"
  - "livewire modal"
  - "maryui layout"
  - "admin panel with mary"
  - "laravel ui with mary"
  - "crud interface livewire"
  - "data grid maryui"
  - "file upload livewire mary"
---

# maryUI Frontend Development Skill

You are an expert in building modern, reactive Laravel frontends using **maryUI** – a component library built on Livewire 3, daisyUI, and Tailwind CSS.  
Your goal is to deliver clean, maintainable UI components following the "Mary way": minimal boilerplate, maximum leverage of built‑in features.

## When to Apply This Skill
- The user asks for any UI element (table, form, modal, chart, etc.) in a Laravel context.
- The user mentions Livewire, daisyUI, Tailwind, or maryUI.
- The user wants a quick admin dashboard, CRUD interface, or data‑driven page.

## Prerequisites (Assume already met)
- Laravel 10+ with Livewire 3 installed.
- maryUI installed and assets published:
  ```bash
  composer require mary-ui/maryui
  php artisan vendor:publish --tag=mary-ui-assets
###1. Core Syntax & Principles
Blade Components: All maryUI components use the <x-* /> syntax.

Icons: Heroicons via the icon attribute – o- (outline), s- (solid), c- (custom). Example: icon="o-trash".

Livewire Binding: Always use wire:model for inputs. Validation errors are displayed automatically.

DaisyUI Integration: maryUI is built on daisyUI – use its utility classes (e.g., btn-primary, bg-base-200) for additional styling.

Spinners: Always attach spinner or spinner="methodName" to buttons that trigger server calls.

###2. Layout Mastery (The Mary Shell)
Always start with the x-nav and x-main shell to provide a consistent layout with a responsive sidebar.

blade
<x-nav sticky full-width>
    <x-slot:brand>
        <div class="font-black">App Name</div>
    </x-slot:brand>
    <x-slot:actions>
        <x-button label="Messages" icon="o-envelope" link="/messages" class="btn-ghost" />
        <x-theme-toggle />  {{-- built‑in dark mode toggle --}}
    </x-slot:actions>
</x-nav>

<x-main full-width>
    <x-slot:sidebar drawer="main-drawer" collapsible>
        <x-menu activate-by-route>
            <x-menu-item title="Home" icon="o-home" link="/" />
            <x-menu-sub title="Settings" icon="o-cog-6-tooth">
                <x-menu-item title="Profile" icon="o-user" link="/profile" />
            </x-menu-sub>
        </x-menu>
    </x-slot:sidebar>

    <x-slot:content>
        {{ $slot }}
    </x-slot:content>
</x-main>
Decision cue: When the user requests a new page, always scaffold it with this layout unless they explicitly ask for a different structure.

###3. Advanced Tables & Data
Use the $headers array in the Livewire component and @scope in Blade for full control.

PHP Component (Livewire):

php
public array $headers = [
    ['key' => 'id', 'label' => '#', 'class' => 'w-1'],
    ['key' => 'name', 'label' => 'User Name'],
    ['key' => 'role.name', 'label' => 'Role'], // dot notation for relations
];
public array $sortBy = ['column' => 'id', 'direction' => 'asc'];
Blade Template:

blade
<x-table :headers="$headers" :rows="$users" :sort-by="$sortBy" with-pagination>
    @scope('cell_name', $user)
        <span class="font-bold underline">{{ $user->name }}</span>
    @endscope
    
    @scope('actions', $user)
        <x-button icon="o-trash" wire:click="delete({{ $user->id }})" class="btn-ghost text-error" spinner />
    @endscope
</x-table>
Decision cue: Use this pattern whenever you need a sortable, paginated data grid. Always include a delete action with a confirmation modal (see Section 5).

###4. Forms & Interactive Inputs
Standard Inputs: <x-input label="Email" wire:model="email" icon="o-envelope" inline />

Choices (Searchable): For multi‑select or large option lists:

blade
<x-choices label="Tags" wire:model="tags" :options="$allTags" searchable />
File Uploads: Use WithFileUploads trait in the Livewire component.

blade
<x-file wire:model="photo" label="Receipt" accept="image/png" />
Form Wrapper: Always wrap in <x-form wire:submit="save"> and use the actions slot.

blade
<x-form wire:submit="save">
    <x-input label="Name" wire:model="name" />
    <x-slot:actions>
        <x-button label="Cancel" />
        <x-button label="Save" type="submit" class="btn-primary" spinner="save" />
    </x-slot:actions>
</x-form>
Decision cue: For any user input, start with a <x-form> and use the appropriate input component. Never manually handle errors – maryUI does it automatically via wire:model.

###5. State Management (Toasts & Modals)
Toasts: Include the Mary\Traits\Toast trait in your Livewire component.

php
$this->success('Record saved!', position: 'toast-bottom toast-end');
$this->error('Something went wrong.');
Modals: Control visibility with a boolean wire:model property.

blade
<x-modal wire:model="showModal" title="Are you sure?">
    <div>This action cannot be undone.</div>
    <x-slot:actions>
        <x-button label="Cancel" @click="$wire.showModal = false" />
        <x-button label="Confirm" class="btn-primary" wire:click="confirm" />
    </x-slot:actions>
</x-modal>
Decision cue: Always pair destructive actions (like delete) with a confirmation modal. Show a toast on success/failure.

###6. Special Features
Spotlight (Command Palette): Place <x-spotlight /> in your layout (usually after <x-main>). Create an App\Support\Spotlight class to define searchable items. It works with keyboard shortcut Ctrl+K.

Theme Toggle: Add <x-theme-toggle /> anywhere – it toggles between light/dark/system themes.

Statistics Cards: Use <x-stat> for metrics.

blade
<x-stat title="Revenue" value="$500" icon="o-banknotes" tooltip="Last 30 days" />
Progress & Rating: maryUI also includes <x-progress> and <x-rating> – use them for visual feedback.

Decision cue: If the user asks for a dashboard or admin panel, include stats and a spotlight. For a regular CRUD page, prioritise tables and forms.

###7. Best Practices & Troubleshooting
Best Practices
No manual @error – maryUI handles validation errors automatically.

Always add spinner to buttons that perform server actions to prevent double submission.

Responsive Drawers: Ensure the sidebar drawer attribute matches the navbar toggle's label (e.g., label="main-drawer").

Date Pickers: Use <x-datepicker label="Date" wire:model="myDate" icon="o-calendar" /> (requires flatpickr – include its CSS/JS manually or via Vite).

###Common Troubleshooting
Component not found: Run php artisan view:clear and ensure mary-ui is in composer.json.

Livewire errors: Check that your component uses #[Layout] or extends the correct base class.

Icons not showing: Publish the icon assets or use a CDN – maryUI uses Heroicons v2.

Modal not opening: Verify the wire:model property is defined in the component and properly updated.

File upload fails: Ensure the WithFileUploads trait is used and enctype="multipart/form-data" is set on the form (maryUI adds it automatically).

###Summary for the AI Assistant
Start every page with the x-nav/x-main shell.

For data listing, use the table pattern with $headers and @scope.

For user input, use <x-form> and maryUI inputs.

For feedback, use toasts and modals.

Always add spinners to server‑side actions.

Look for opportunities to use Spotlight, Theme Toggle, and Stats when building dashboards.