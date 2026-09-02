# Plan 015: Remove Unused Packages (verta + openai-php/client)

**Created:** 2026-09-02  
**Branch:** tannaz  
**Planned at:** cf3cf9c  
**Priority:** Low  
**Category:** Cleanup  

## Problem

Two packages in `composer.json` are unused:
1. **`hekmatinasser/verta`** (Jalali/Persian calendar) — zero imports in the codebase (only in vendor files). The project uses `morilog/jalali` for Jalali dates instead.
2. **`openai-php/client`** — the only reference is in `config/ai.php`, which configures the client but is never called at runtime. No controllers, services, or commands import or use this package.

Both packages add:
- Unnecessary attack surface (supply chain risk)
- Increased `composer.lock` size
- Extra autoloader entries
- Potential dependency conflicts

## Current State

### composer.json

```json
"require": {
    "php": "^8.3",
    "hekmatinasser/verta": "^9.0",       // ← UNUSED
    "laravel/boost": "^2.7",
    "laravel/framework": "^13.0",
    "laravel/sanctum": "^4.0",
    "laravel/tinker": "^3.0",
    "livewire/livewire": "^4.0",
    "maatwebsite/excel": "^4.0",
    "morilog/jalali": "3.5",             // ← Used for Jalali dates
    "openai-php/client": "^0.20.1",      // ← UNUSED
    "robsontenorio/mary": "^2.8.1",
    "spatie/laravel-permission": "^8.0"
}
```

### config/ai.php (to be deleted)

```php
return [
    'default' => env('AI_PROVIDER', 'openai'),
    'model' => env('AI_MODEL', 'code'),
    'providers' => [
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
        ],
    ],
];
```

### Import verification

```bash
# verta — zero imports in app/ code
grep -r "Hekmatinasser\\Verta\|Verta" app/ tests/
# Expected: 0 matches

# openai-php — zero runtime usage
grep -r "OpenAI\|openai-php" app/ tests/
# Expected: 0 matches (only config/ai.php references it)
```

## Proposed Fix

### 1. Remove packages from composer.json

```json
"require": {
    "php": "^8.3",
    "laravel/boost": "^2.7",
    "laravel/framework": "^13.0",
    "laravel/sanctum": "^4.0",
    "laravel/tinker": "^3.0",
    "livewire/livewire": "^4.0",
    "maatwebsite/excel": "^4.0",
    "morilog/jalali": "3.5",
    "robsontenorio/mary": "^2.8.1",
    "spatie/laravel-permission": "^8.0"
}
```

### 2. Delete config/ai.php

```bash
rm config/ai.php
```

### 3. Run composer update

```bash
composer remove hekmatinasser/verta openai-php/client
# This handles: composer.json update + composer.lock update + package discovery
```

## Files to Modify

| File | Change |
|------|--------|
| `composer.json` | Remove `hekmatinasser/verta` and `openai-php/client` from `require` |
| `composer.lock` | Auto-updated by `composer remove` |
| `config/ai.php` | Delete file |

**Out of scope:** `morilog/jalali` (actively used), `config/ai.php` references in service providers.

## Verification

```bash
# 1. Remove packages
composer remove hekmatinasser/verta openai-php/client

# 2. Verify no references remain
grep -r "verta\|Verta" app/ tests/ config/ routes/
# Expected: 0 matches

grep -r "openai\|OpenAI" app/ tests/ config/ routes/
# Expected: 0 matches (config/ai.php is deleted)

# 3. Verify config/ai.php is gone
ls config/ai.php
# Expected: No such file or directory

# 4. Run composer audit
composer audit
# Expected: clean (or only unrelated advisories)

# 5. Run full test suite
composer test
# Expected: 928+ pass, 0 fail

# 6. Verify autoloader
composer dump-autoload
php artisan route:list
# Expected: no errors
```

## Test Plan

```php
it('does not have hekmatinasser/verta in composer.json', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($composer['require'])->not->toHaveKey('hekmatinasser/verta');
});

it('does not have openai-php/client in composer.json', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);
    expect($composer['require'])->not->toHaveKey('openai-php/client');
});

it('config/ai.php does not exist', function () {
    expect(file_exists(config_path('ai.php')))->toBeFalse();
});

it('no Verta class references in app code', function () {
    $files = glob(resource_path('**/*.php'));
    foreach ($files as $file) {
        $content = file_get_contents($file);
        expect($content)->not->toContain('Verta');
    }
});
```

## STOP Conditions

- If `grep` reveals any import of `Verta` or `OpenAI` in `app/` or `tests/` (must investigate first)
- If the project has hidden config bindings for `config/ai.php` in a service provider
- If `composer remove` triggers unexpected dependency resolution failures
- If `morilog/jalali` also depends on `hekmatinasser/verta` (unlikely, but verify)

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Hidden dependency on verta | Runtime error | Grep for all references before removal |
| OpenAI client used in background job | Feature breaks | Audit queue jobs and scheduled commands |
| composer.lock conflicts | Install fails | Run `composer update` after removal |
| config/ai.php referenced elsewhere | Config error | Check for `config('ai.')` references |

## Maintenance Notes

- Add `composer audit` to CI/CD pipeline to catch unused or vulnerable packages
- Periodically review `composer.json` for packages with no imports
- If OpenAI integration is needed in the future, re-add with a clear use case
- The `config/ai.php` file can be recreated when the feature is actually implemented
