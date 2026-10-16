# Persian Search & Normalization

| Canonical repo: https://github.com/asgarimehdi/h-dashboard (base branch `beta`).
| Every clone of it is a fork — never hardcode a fork URL or branch, read
| `git remote -v` / `git branch --show-current` first.
| Split out of `AGENTS.md` on 2026-10-16; that file keeps the one-line rules and
| the map of where each topic lives.

Everything the search stack needs. `normalizeForSearch()` / `escapeLikeWildcards()` /
`foldSeparatorsSql()` / `foldedTerm()` live in `app/Traits/PersianNormalizer.php`; the models
normalize on save carry the PersianNormalizer trait.

| Gotcha | Details |
|---|---|
| Search | Multi-word queries split and matched independently via `scopeFilterSearch` |
| `normalizeForQuery` | Use `PersianNormalizer::normalizeForQuery()` for user-supplied LIKE queries ONLY on columns the model normalizes on save (`Person` fields, `Hardware` fields like `pc_name`/`type`/`os`/`comments`) — combines Persian normalization + wildcard escaping. Do NOT inline `str_replace(['%', '_'], ...)`. Lookup-table columns (`units.name`, `semats.name`, `radifs.name`, `tahsils.name`, `estekhdams.name`) have no save hook: fold the COLUMN with `foldSeparatorsSql($col)` and the term with `foldedTerm($input)` (#815) — see the "fold the COLUMN" row below. Folding a **hooked** column too is not a contradiction: it is the identity on hook-written rows and is what keeps two surfaces in agreement for rows that bypassed the hook — `PersonsTableSeeder` inserts through `DB::table('persons')->insert(...)`, so a seeded name keeps its raw code points, and `PersonsExportController` folds `CONCAT(f_name, ' ', l_name)` / `CONCAT(l_name, ' ', f_name)` for exactly that reason (#940) |
| `PersianNormalizer` trait | Located at `app/Traits/PersianNormalizer.php`. Methods: `normalizeForSearch()` (Arabic→Persian + Unicode), `escapeLikeWildcards()`, `normalizeForQuery()` (normalize + escape combined) |
| Persian search must fold the COLUMN, not the pattern | `normalizeForQuery()` rewrites the search term (ZWNJ U+200C → space; آ/أ/إ U+0622/0623/0625 → ا) but the stored text keeps the original code points, so `LIKE` stops matching — a unit named "حرفه" + ZWNJ + "ای" or "آموزش" becomes invisible to its own filter. Postgres `LIKE` has only `%`/`_` and **no character-class syntax**, so `[ … ]` is literal and "either spelling" is unexpressible. Use `PersianNormalizer::foldSeparatorsSql($column)` (nested `regexp_replace` applying the same `charMap()` as `normalize()`, plus one `translate()` applying `digitMap()` so Persian/Arabic-Indic digits fold to Latin exactly as `normalizeForSearch()` does) compared against `foldedTerm($input)`. Replace ZWNJ with a SPACE, never `''` — deleting it makes the regex eat the next letter ("حرفه" + ZWNJ + "ای" → "حرفهای") |
| `LIKE '%term%'` is never index-seekable | Folding the column in `foldSeparatorsSql()` is not index-friendly, but `LIKE '%term%'` already forced a full scan, so this is not a regression to worry about |
| Build the search term list ONCE, from `normalizeForSearch()` — never index a second list by its positions | `normalizeForSearch()` maps ZWNJ/ZWJ **and** any `\s+` run to a SPACE, so a Persian compound like «خانه بهداشت» or «حرفه ای» yields **more** terms than the raw string has words. Building `$rawTerms`/`$foldedTerms` from the raw string and reading `$foldedTerms[$i]` at `$terms`' positions walks off the end: `ErrorException: Undefined array key` → 500 on `GET /kargozini/persons/export` for anyone typing a ZWNJ compound and clicking «خروجی اکسل» (regression from #815, fixed by #940). Split `normalizeForSearch($search)` once and call `foldedTerm($term)` **inside** the loop, exactly like `resources/views/livewire/kargozini/person.blade.php`. Do **not** derive the list from `normalizeForQuery()` and fold again — that output is already escaped, so the second `escapeLikeWildcards()` double-escapes `%`/`_` into `\%\%` and the search silently stops matching |
