<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v4
- livewire/volt (VOLT) - v1
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- spatie/laravel-permission (PERMISSION) - v8
- mockery/mockery (MOCKERY) - v1
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== livewire/volt rules ===

# Livewire 4 (Single-File Components)

- This project uses **Livewire 4 native single-file components** (NOT Livewire Volt).
- Single-file components: PHP logic and Blade templates in one `.blade.php` file under `resources/views/livewire/`.
- ALL Livewire components use the anonymous class pattern: `return new class extends Component { ... };`
- Route registration: `Route::livewire('/path', 'component.file');` — maps to `resources/views/livewire/{file}.blade.php`.
- There is NO `app/Livewire/` directory. All component logic lives inside blade files.
- Always check existing components to determine the correct structure and naming conventions.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest PHP Testing Framework

- This application uses Pest for testing. All tests must be written as Pest functions, not PHPUnit classes.
- PRIMARY: use `vendor/bin/pest` or `vendor/bin/pest --compact`.
- Run `vendor/bin/pest` instead of `php artisan test`.
- Run `vendor/bin/pest --filter=testName` when filtering.
- Use `test()` and `expect()` syntax.
- For Laravel-specific tests, use `$this->get()`, `$this->post()`, etc. from the TestCase.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `vendor/bin/pest --compact`.
- To run all tests in a file: `vendor/bin/pest --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `vendor/bin/pest --compact --filter=testName` (recommended after making a change to a related file).

=== project-specific rules ===

# h-dashboard Project Context

## Tech Stack
- PHP 8.4, Laravel 13, Livewire 4, MaryUI (UI components), Tailwind CSS + DaisyUI
- Jalali dates (morilog/jalali), Persian/RTL layout
- Highcharts (charts), Leaflet.js (maps), FullCalendar (todo calendar)

## Key Model Relationships
- User → Person: via `n_code` (User.belongsTo Person using 'n_code', 'n_code')
- Person → Unit: via `u_id` (Person.belongsTo Unit)
- User → Unit: through person (`auth()->user()->person?->u_id`)
- Todo ↔ Users: many-to-many via `todo_user` pivot table
- Todo → Unit: belongsTo via `unit_id`

## Modules
- Dashboard (`/dashboard`) — stat cards for all modules
- Users (`/users`) — CRUD with roles/permissions
- Units (`/units`, `/units/chart`) — organizational hierarchy
- Todo/Calendar (`/todo`) — FullCalendar with multi-user assignment
- Tickets (`/tickets/new`, `/tickets/inbox`, `/monitoring`) — ticketing workflow
- Maps (`/maps/*`) — Leaflet GIS features
- IT Monitoring (`/it/wireless`, `/it/networks`) — Zabbix integration
- Personnel/Kargozini (`/kargozini/*`) — persons, estekhdams, tahsils, semats, radifs
- Roles & Permissions (`/roles`, `/permissions`) — Spatie Permission

## UI Component Patterns
- MaryUI `<x-stat>` for dashboard stat cards
- MaryUI `<x-choices-offline>` for multi-select (option-value, option-label)
- MaryUI `<x-select>` for single select
- MaryUI `<x-card>`, `<x-modal>`, `<x-form>`, `<x-input>`, `<x-toggle>`, `<x-badge>`
- `<x-header>` with separator and progress-indicator on every page

## Permissions (Spatie)
- Named permissions: `kargozini`, `map`, `organization`, `op-cache`, `bw`, `calendar`, `view_all_tickets`, `create_ticket`, `manage_unit_tickets`, `view_assigned_tickets`
- Route middleware: `role_or_permission:permission_name`
- Seeder uses `firstOrCreate` to avoid duplicates

## Conventions
- Always run `vendor/bin/pint --dirty --format agent` after modifying PHP files
- Use `auth()->user()->person?->u_id` to get logged-in user's unit
- User names: `$user->person?->f_name . ' ' . $user->person?->l_name`
- Users filtered by unit: `User::whereHas('person', fn($q) => $q->where('u_id', $unitId))`

</laravel-boost-guidelines>
