<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Drift guard for every app-owned maryUI component mirror (#1480).
 *
 * Each contract below declares how one app component turns maryUI's vendored
 * `render()` heredoc into its own markup: an ordered list of anchored edits
 * (`replace`, `drop`, block variants, inserts). The guard applies those edits to
 * the vendor heredoc and asserts the result is line-identical to the mirror's
 * heredoc. A maryUI upgrade that changes one of those seven components turns
 * this red instead of turning production quietly wrong.
 *
 * Structural divergence (an `x-data` literal replaced by a named Alpine factory
 * plus `data-*` attributes) is legal only for an edit that removes vendor
 * markup, and only when the equivalent logic is present in
 * `resources/js/mary-components.js`. The CSP assertions and the `data-*`
 * mapping check keep that equivalence honest in both directions.
 *
 * Extending coverage: never add rows and leave the provider empty — adding an
 * alias to `AppServiceProvider::boot()` without a contract here, or adding a
 * component heredoc that is neither a contract nor a declared `NON_MIRRORS`
 * entry, fails on its own.
 */
class MaryUiMirrorTest extends TestCase
{
    private const JS_MODULE = 'resources/js/mary-components.js';

    private const PROVIDER = 'app/Providers/AppServiceProvider.php';

    /**
     * alias => contract. The alias set is derived from AppServiceProvider, never
     * trusted from this list (see test_every_alias_has_a_contract).
     *
     * `vendor`/`mirror` are repository-relative paths. `factory` is the Alpine
     * component name the mirror binds with `x-data` (null only for a mirror that
     * re-introduces no state). `js_requires` are literals that must exist in
     * that factory's body, justifying every vendor line the ops remove. `ops`
     * are applied in order; see applyOps() for the semantics.
     */
    private const MIRRORS = [
        'table' => [
            'vendor' => 'vendor/robsontenorio/mary/src/View/Components/Table.php',
            'mirror' => 'app/View/Components/CspTable.php',
            'factory' => 'maryTable',
            'js_requires' => [
                'wireGet(this.$wire, this.modelName)',
                'this.handleCheckAll()',
                'this.selection.push(...this.pageIds',
            ],
            'ops' => [
                ['op' => 'replace_block', 'start' => '<div x-data="{', 'end' => '}"', 'with' => [
                    '<div x-data="maryTable"',
                    'data-model="{{ $attributes->wire(\'model\') ? $attributes->wire(\'model\')->value() : \'\' }}"',
                    'data-selectable="{{ json_encode($selectable) }}"',
                    'data-page-ids="{{ json_encode($getAllIds()) }}"',
                ]],
                ['op' => 'insert_before', 'anchor' => '@click="$wire.set(\'{{ $sortByProperty }}\', {column: \'{{ $getSort($header)[\'column\'] }}\', direction: \'{{ $getSort($header)[\'direction\'] }}\' })"', 'lines' => [
                    'aria-sort="{{ $ariaSort($header) }}"',
                    'tabindex="0"',
                ]],
                ['op' => 'insert_after', 'anchor' => '@click="$wire.set(\'{{ $sortByProperty }}\', {column: \'{{ $getSort($header)[\'column\'] }}\', direction: \'{{ $getSort($header)[\'direction\'] }}\' })"', 'lines' => [
                    '@keydown.enter.prevent="$wire.set(\'{{ $sortByProperty }}\', {column: \'{{ $getSort($header)[\'column\'] }}\', direction: \'{{ $getSort($header)[\'direction\'] }}\' })"',
                    '@keydown.space.prevent="$wire.set(\'{{ $sortByProperty }}\', {column: \'{{ $getSort($header)[\'column\'] }}\', direction: \'{{ $getSort($header)[\'direction\'] }}\' })"',
                ]],
                ['op' => 'replace', 'line' => '::class="isExpanded({{ $getKeyValue($row, \'expandableKey\') }}) || \'ltr:-rotate-90 rtl:rotate-90 !text-current\'"', 'count' => 1, 'with' => [
                    '::class="isExpanded({{ $getKeyValue($row, \'expandableKey\') }}) ? \'\' : \'ltr:-rotate-90 rtl:rotate-90 !text-current\'"',
                ]],
                ['op' => 'replace', 'line' => '<tr wire:key="{{ $uuid }}-{{ $k }}--expand" class="!bg-inherit" :class="isExpanded({{ $getKeyValue($row, \'expandableKey\') }}) || \'hidden\'">', 'count' => 1, 'with' => [
                    '<tr wire:key="{{ $uuid }}-{{ $k }}--expand" class="!bg-inherit" :class="isExpanded({{ $getKeyValue($row, \'expandableKey\') }}) ? \'\' : \'hidden\'">',
                ]],
            ],
        ],

        'menu-item' => [
            'vendor' => 'vendor/robsontenorio/mary/src/View/Components/MenuItem.php',
            'mirror' => 'app/View/Components/AccessibleMenuItem.php',
            'factory' => null,
            'js_requires' => [],
            'ops' => [
                ['op' => 'insert_before', 'anchor' => '@if($getHref())', 'lines' => [
                    '@if($getHref() && ($active || ($activateByRoute && $routeMatches())))',
                    'aria-current="page"',
                    '@endif',
                ]],
            ],
        ],

        'main' => [
            'vendor' => 'vendor/robsontenorio/mary/src/View/Components/Main.php',
            'mirror' => 'app/View/Components/CspMain.php',
            'factory' => 'marySidebar',
            'js_requires' => [
                'fetch(this.collapseUrl',
                "'sidebar-toggled'",
                'onMenuSubClicked',
            ],
            'ops' => [
                ['op' => 'replace_block', 'start' => 'x-data="{', 'end' => '}"', 'with' => [
                    'x-data="marySidebar"',
                    'data-collapsed="{{ session(\'mary-sidebar-collapsed\', \'false\') }}"',
                    'data-collapse-text="{{ $collapseText }}"',
                    'data-collapse-url="{{ $url }}"',
                ]],
                ['op' => 'replace', 'line' => '@menu-sub-clicked="if(collapsed) { toggle() }"', 'count' => 1, 'with' => [
                    '@menu-sub-clicked="onMenuSubClicked()"',
                ]],
            ],
        ],

        'menu-sub' => [
            'vendor' => 'vendor/robsontenorio/mary/src/View/Components/MenuSub.php',
            'mirror' => 'app/View/Components/CspMenuSub.php',
            'factory' => 'maryMenuSub',
            'js_requires' => [
                "'menu-sub-clicked'",
                'this.show = !this.show',
            ],
            'ops' => [
                ['op' => 'replace_block', 'start' => 'x-data="', 'end' => '}"', 'with' => [
                    'x-data="maryMenuSub"',
                    'data-show="{{ ($submenuActive || $open) && !$horizontal ? \'true\' : \'false\' }}"',
                ]],
            ],
        ],

        'modal' => [
            'vendor' => 'vendor/robsontenorio/mary/src/View/Components/Modal.php',
            'mirror' => 'app/View/Components/CspModal.php',
            'factory' => 'maryModal',
            'js_requires' => [
                "'close'",
                "'open'",
                'wireSet(this.$wire, this.modelName, false)',
                "'modal-open'",
            ],
            'ops' => [
                ['op' => 'replace', 'line' => 'x-data="{open: @entangle($attributes->wire(\'model\')).live }"', 'count' => 1, 'with' => [
                    'x-data="maryModal"',
                    'data-model="{{ $attributes->wire(\'model\')->value() }}"',
                    'data-persistent="{{ $persistent ? \'true\' : \'false\' }}"',
                ]],
                ['op' => 'drop', 'line' => 'x-init="$watch(\'open\', value => { if (!value){ $dispatch(\'close\') }else{ $dispatch(\'open\') } })"', 'count' => 1],
                ['op' => 'drop', 'line' => ':class="{\'modal-open !animate-none\': open}"', 'count' => 1],
                ['op' => 'drop_block', 'start' => '@if(!$persistent)', 'end' => '@endif'],
                ['op' => 'replace', 'line' => '<x-mary-button class="btn-circle btn-sm btn-ghost absolute end-2 top-2 z-[999]" icon="o-x-mark" @click="$wire.{{ $attributes->wire(\'model\')->value() }} = false" tabindex="-1" />', 'count' => 1, 'with' => [
                    '<x-mary-button class="btn-circle btn-sm btn-ghost absolute end-2 top-2 z-[999]" icon="o-x-mark" tabindex="-1" />',
                ]],
                ['op' => 'replace', 'line' => '<button @click="$wire.{{ $attributes->wire(\'model\')->value() }} = false" type="button">close</button>', 'count' => 1, 'with' => [
                    '<button type="button">close</button>',
                ]],
            ],
        ],

        'choices-offline' => [
            'vendor' => 'vendor/robsontenorio/mary/src/View/Components/ChoicesOffline.php',
            'mirror' => 'app/View/Components/CspChoicesOffline.php',
            'factory' => 'maryChoices',
            'js_requires' => [
                'choices-options-data',
                'renderSelectionSlots',
                'canRemove',
                'placeholderClass',
                'searchRequired',
            ],
            'ops' => [
                ['op' => 'replace', 'line' => '<div x-data="{ focused: false, selection: @entangle($attributes->wire(\'model\')) }">', 'count' => 1, 'with' => [
                    '<div>',
                ]],
                ['op' => 'replace_block', 'start' => 'x-data="{', 'end' => '}"', 'with' => [
                    'x-data="maryChoices"',
                    'data-model="{{ $attributes->wire(\'model\') ? $attributes->wire(\'model\')->value() : \'\' }}"',
                    'data-choices-id="{{ $uuid }}"',
                    'data-single="{{ json_encode($single) }}"',
                    'data-searchable="{{ json_encode($searchable) }}"',
                    'data-readonly="{{ json_encode($isReadonly()) }}"',
                    'data-disabled="{{ json_encode($isDisabled()) }}"',
                    'data-required="{{ json_encode($isRequired()) }}"',
                    'data-min-chars="{{ $minChars }}"',
                    'data-option-value="{{ $optionValue }}"',
                    'data-option-label="{{ $optionLabel }}"',
                ]],
                ['op' => 'insert_before', 'anchor' => '<fieldset class="fieldset py-0">', 'lines' => [
                    '<script type="application/json" class="choices-options-data">@json($options)</script>',
                ]],
                ['op' => 'replace', 'line' => '/^<span x-html=/', 'count' => 1, 'with' => [
                    '<span data-selection-slot :data-option-value="option.{{ $optionValue }}"></span>',
                ]],
                ['op' => 'replace', 'line' => '<x-mary-icon @click="toggle(option.{{ $optionValue }})" x-show="!isReadonly && !isDisabled && !isSingle" name="o-x-mark" class="w-4 h-4 hover:text-error" />', 'count' => 1, 'with' => [
                    '<x-mary-icon @click="toggle(option.{{ $optionValue }})" x-show="canRemove()" name="o-x-mark" class="w-4 h-4 hover:text-error" />',
                ]],
                ['op' => 'replace', 'line' => '<span :class="(focused || !isSelectionEmpty) && \'hidden\'" class="text-base-content/40">', 'count' => 1, 'with' => [
                    '<span :class="placeholderClass()"  class="text-base-content/40">',
                ]],
                ['op' => 'replace', 'line' => ':required="isRequired && isSelectionEmpty"', 'count' => 1, 'with' => [
                    ':required="searchRequired()"',
                ]],
                ['op' => 'replace', 'line' => ':class="isActive({{ $getOptionValue($option) }}) && \'border-s-4 border-s-base-content\'"', 'count' => 1, 'with' => [
                    ':class="isActive({{ $getOptionValue($option) }}) ? \'border-s-4 border-s-base-content\' : \'\'"',
                ]],
            ],
        ],

        'file' => [
            'vendor' => 'vendor/robsontenorio/mary/src/View/Components/File.php',
            'mirror' => 'app/View/Components/CspFile.php',
            'factory' => 'maryFile',
            'js_requires' => [
                'livewire-upload-progress',
                'progressStyle',
                'this.cropAfterChange',
            ],
            'ops' => [
                ['op' => 'replace_block', 'start' => 'x-data="{', 'end' => '}"', 'with' => [
                    'x-data="maryFile"',
                    'data-model="{{ $attributes->wire(\'model\')->value() }}"',
                    'data-crop-after-change="{{ json_encode($cropAfterChange) }}"',
                    'data-crop-setup="{{ $cropSetup() }}"',
                    'data-crop-mime-type="{{ $cropMimeType }}"',
                ]],
                ['op' => 'drop', 'line' => 'x-on:livewire-upload-progress="progress = $event.detail.progress;"', 'count' => 1],
                ['op' => 'replace', 'line' => ':class="!processing && \'hidden\'"', 'count' => 2, 'with' => [
                    ':class="processing ? \'\' : \'hidden\'"',
                ]],
                ['op' => 'replace', 'line' => ':class="processing && \'opacity-50 pointer-events-none\'"', 'count' => 1, 'with' => [
                    ':class="processing ? \'opacity-50 pointer-events-none\' : \'\'"',
                ]],
                ['op' => 'replace', 'line' => ':style="`--value:${progress}; --size:1.5rem; --thickness: 4px;`"', 'count' => 1, 'with' => [
                    ':style="progressStyle()"',
                ]],
            ],
        ],
    ];

    /**
     * App components that render a heredoc and are NOT mirrors of a vendor
     * component. The value is the justification shown when the accounting
     * fails, so a new unguarded component has to name its reason.
     *
     * @var array<string, string>
     */
    private const NON_MIRRORS = [
        'AppBrand' => 'app-owned brand markup, no vendor counterpart',
    ];

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function contractProvider(): array
    {
        $cases = [];

        foreach (self::MIRRORS as $alias => $contract) {
            $cases[$alias] = [$alias, $contract];
        }

        return $cases;
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function factoryContractProvider(): array
    {
        return array_filter(
            self::contractProvider(),
            fn (array $case): bool => $case[1]['factory'] !== null
        );
    }

    public function test_every_app_component_alias_has_a_mirror_contract(): void
    {
        $source = file_get_contents(base_path(self::PROVIDER));
        $this->assertIsString($source, 'cannot read '.self::PROVIDER);

        preg_match_all(
            '/Blade::component\(\s*\'([^\']+)\'\s*,\s*([A-Za-z0-9_\\\\]+)::class\s*\)/',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $aliases = [];

        foreach ($matches as $match) {
            $short = $match[2];
            $path = 'app/View/Components/'.$short.'.php';

            if (! is_file(base_path($path))) {
                continue; // maryUI's own aliases are not app components
            }

            $aliases[$match[1]] = $path;
        }

        $this->assertSame(
            array_keys(self::MIRRORS),
            array_keys($aliases),
            'AppServiceProvider registers an app-component alias with no contract in MaryUiMirrorTest::MIRRORS, or a contract has no alias. '
            .'Add the alias to MIRRORS and declare its vendor file + ops, or remove the stale contract row.'
        );

        foreach (self::MIRRORS as $alias => $contract) {
            $this->assertSame(
                $contract['mirror'],
                $aliases[$alias],
                "Alias `{$alias}` resolves to {$aliases[$alias]}, but its contract names {$contract['mirror']}."
            );
        }
    }

    public function test_every_component_with_a_render_heredoc_is_accounted_for(): void
    {
        $contracts = [];
        $declared = [];

        foreach (self::MIRRORS as $contract) {
            $contracts[] = basename($contract['mirror'], '.php');
        }

        foreach (array_keys(self::NON_MIRRORS) as $name) {
            $declared[] = $name;
        }

        $heredocs = [];

        foreach ((array) glob(base_path('app/View/Components/*.php')) as $path) {
            if ($this->hasRenderHeredoc($path)) {
                $heredocs[] = basename($path, '.php');
            }
        }

        sort($heredocs);
        $covered = array_values(array_unique(array_merge($contracts, $declared)));
        sort($covered);

        $this->assertSame(
            $covered,
            $heredocs,
            'Every app/View/Components class with a render() heredoc must be a MaryUiMirrorTest::MIRRORS contract '
            .'or a declared NON_MIRRORS entry with its reason. Unguarded components drift silently on a maryUI upgrade.'
        );
    }

    /**
     * @param  array<string, mixed>  $contract
     */
    #[DataProvider('contractProvider')]
    public function test_the_mirror_is_the_vendor_heredoc_plus_its_declared_edits(string $alias, array $contract): void
    {
        $vendorLines = $this->significantLines($contract['vendor']);
        $mirrorLines = $this->significantLines($contract['mirror']);

        $rebuilt = $this->applyOps($contract['ops'], $vendorLines, $alias, $contract['vendor'], $contract['mirror']);

        $this->assertLinesIdentical(
            $mirrorLines,
            $rebuilt,
            "`{$alias}` contract no longer reproduces {$contract['mirror']} from {$contract['vendor']}. "
            .'A vendor line with no op means maryUI changed its markup: re-sync the mirror, or add the op if the change is deliberate.'
        );
    }

    /**
     * A removing op (drop, drop_block, replace_block) discards vendor markup, so
     * it is legal only when a named Alpine factory owns the behaviour. Every
     * js_requires literal must exist in that factory's body — rename the JS and
     * this contract has to say so.
     *
     * @param  array<string, mixed>  $contract
     */
    #[DataProvider('contractProvider')]
    public function test_removed_vendor_markup_is_implemented_in_the_named_alpine_factory(string $alias, array $contract): void
    {
        $removing = array_values(array_filter(
            $contract['ops'],
            fn (array $op): bool => in_array($op['op'], ['drop', 'drop_block', 'replace_block'], true)
        ));

        if ($contract['factory'] === null) {
            $this->assertSame(
                [],
                $removing,
                "`{$alias}` has no Alpine factory, so it may not remove vendor markup. Only a factory-backed contract can."
            );

            $this->assertSame([], $contract['js_requires'], "`{$alias}` has no factory but declares js_requires.");

            return;
        }

        if ($removing === []) {
            return;
        }

        $this->assertNotSame([], $contract['js_requires'], "`{$alias}` removes vendor markup without a js_requires justification.");

        $js = $this->jsModule();
        $factoryBody = $this->factoryBody($js, (string) $contract['factory']);

        foreach ($contract['js_requires'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $factoryBody,
                "`{$alias}` says the removed vendor markup lives in `{$contract['factory']}`, but that factory has no `{$needle}`. "
                ."Update the contract's js_requires if the JS equivalent was renamed."
            );
        }
    }

    /**
     * The reason these mirrors exist: the CSP-safe Alpine build cannot evaluate
     * an `x-data` object literal, `@entangle`, `x-init`, `x-on:`, `x-html`, or a
     * `@script` block — and a `$wire` assignment through the parser is a silent
     * no-op (#1352). Assertions run on the heredoc body only: the class
     * docblocks legitimately discuss the banned directives as prose.
     *
     * @param  array<string, mixed>  $contract
     */
    #[DataProvider('contractProvider')]
    public function test_the_mirror_heredoc_obeys_the_csp_contract(string $alias, array $contract): void
    {
        $body = implode("\n", $this->significantLines($contract['mirror']));

        preg_match_all('/x-data="([^"]*)"/', $body, $dataMatches);

        $this->assertSame(
            $contract['factory'] === null ? [] : [$contract['factory']],
            $dataMatches[1],
            "`{$alias}` must bind exactly one x-data value — the bare Alpine factory name, never an expression."
        );

        foreach ($dataMatches[1] as $value) {
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z_$][\w$]*$/',
                $value,
                "`{$alias}` reintroduced an inline x-data expression `{$value}`; that is the reason these mirrors exist."
            );
        }

        foreach (['@entangle', 'x-init=', 'x-on:', 'x-html', '@script'] as $banned) {
            $this->assertStringNotContainsString(
                $banned,
                $body,
                "`{$alias}` contains `{$banned}`, which the CSP-safe Alpine build cannot evaluate."
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/@[A-Za-z][\w.:-]*\s*=\s*"[^"]*\$wire\.[\w.]+\s*=/',
            $body,
            "`{$alias}` assigns a \$wire property through a directive; the CSP parser makes that a silent no-op (#1352)."
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\son[a-z]+\s*=\s*"/',
            $body,
            "`{$alias}` contains a native on*=\"…\" handler, which CSP blocks."
        );

        if ($contract['factory'] !== null) {
            $this->assertSame(
                1,
                substr_count($this->jsModule(), "Alpine.data('{$contract['factory']}'"),
                "Alpine factory `{$contract['factory']}` must be registered exactly once in ".self::JS_MODULE.'.'
            );
        }
    }

    /**
     * `data-*` attributes and the factory's `dataset` reads are one contract in
     * two directions: every attribute a contract introduces must be consumed by
     * the named factory, and every dataset read must be backed by a rendered
     * attribute. Renaming one side alone is drift.
     *
     * @param  array<string, mixed>  $contract
     */
    #[DataProvider('factoryContractProvider')]
    public function test_the_data_attributes_and_the_factory_reads_agree(string $alias, array $contract): void
    {
        $factory = (string) $contract['factory'];
        $factoryBody = $this->factoryBody($this->jsModule(), $factory);

        $mirrorBody = implode("\n", $this->significantLines($contract['mirror']));
        $vendorBody = implode("\n", $this->significantLines($contract['vendor']));

        $introduced = array_values(array_diff(
            $this->dataAttributes($mirrorBody),
            $this->dataAttributes($vendorBody)
        ));

        foreach ($introduced as $name) {
            $camel = $this->camelCase($name);

            $this->assertMatchesRegularExpression(
                '/data-'.preg_quote($name, '/').'\b|\b(?:ds|dataset)\.'.preg_quote($camel, '/').'\b/',
                $factoryBody,
                "`{$alias}` renders `data-{$name}` but `{$factory}` never reads it — the server and the factory disagree."
            );
        }

        preg_match_all('/\b(?:ds|dataset)\.([A-Za-z0-9_]+)/', $factoryBody, $reads);

        foreach (array_unique($reads[1]) as $camel) {
            $kebab = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $camel));

            $this->assertStringContainsString(
                'data-'.$kebab,
                $mirrorBody,
                "`{$factory}` reads `dataset.{$camel}`, but {$contract['mirror']} renders no `data-{$kebab}` attribute."
            );
        }
    }

    public function test_the_mirror_inventory_table_lists_every_contract(): void
    {
        $doc = file_get_contents(base_path('docs/agents/features.md'));
        $this->assertIsString($doc, 'cannot read docs/agents/features.md');

        $heading = '## maryUI component mirrors (#1480)';
        $start = strpos($doc, $heading);
        $this->assertNotFalse($start, "docs/agents/features.md has no `{$heading}` section.");

        $rest = substr($doc, $start + strlen($heading));
        $end = strpos($rest, "\n## ");
        $section = $end === false ? $rest : substr($rest, 0, $end);

        $rows = [];

        foreach (explode("\n", $section) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '| ')));

            if ($cells === ['Blade alias', 'App component', 'Vendor class (locked mary 2.9.10)', 'Alpine factory', 'Contract delta', 'Introduced by']) {
                continue;
            }

            if (str_starts_with($cells[0], '---')) {
                continue;
            }

            $rows[] = [
                trim($cells[0], '`'),
                trim($cells[1], '`'),
                trim($cells[2], '`'),
                trim($cells[3], '`'),
            ];
        }

        $expected = [];

        foreach (self::MIRRORS as $alias => $contract) {
            $expected[] = [
                $alias,
                basename($contract['mirror'], '.php'),
                basename($contract['vendor']),
                $contract['factory'] ?? '—',
            ];
        }

        $this->assertSame(
            $expected,
            $rows,
            'The maryUI mirror inventory table in docs/agents/features.md disagrees with MIRRORS '
            .'(alias, app component, vendor class, Alpine factory). Update the table after a mary upgrade.'
        );
    }

    /**
     * Apply the contract's anchored edits in order.
     *
     * Op semantics (`pattern` is an exact trimmed line, or a `/regex/`):
     *  - replace / drop: pattern must match exactly `count` (default 1) times.
     *  - replace_block / drop_block: inclusive, first start → first end after it.
     *  - insert_before / insert_after: anchor must match exactly once.
     *
     * @param  array<int, array<string, mixed>>  $ops
     * @param  array<int, string>  $vendorLines
     * @return array<int, string>
     */
    private function applyOps(array $ops, array $vendorLines, string $alias, string $vendorPath, string $mirrorPath): array
    {
        $lines = $vendorLines;

        foreach ($ops as $position => $op) {
            $label = "contract `{$alias}` op #".($position + 1)." (`{$op['op']}`) against {$vendorPath}";
            $kind = $op['op'];

            if ($kind === 'replace' || $kind === 'drop') {
                $matches = $this->matchingIndexes($lines, (string) $op['line']);
                $expected = (int) ($op['count'] ?? 1);

                $this->assertCount(
                    $expected,
                    $matches,
                    "{$label}: `{$op['line']}` matched ".count($matches)." times, expected {$expected}. "
                    ."If maryUI changed this line, re-sync {$mirrorPath} and update the op."
                );

                /** @var array<int, string> $replacement */
                $replacement = $kind === 'replace' ? array_values((array) $op['with']) : [];

                $out = [];
                $cursor = 0;

                foreach ($matches as $match) {
                    $out = array_merge($out, array_slice($lines, $cursor, $match - $cursor), $replacement);
                    $cursor = $match + 1;
                }

                $lines = array_merge($out, array_slice($lines, $cursor));

                continue;
            }

            if ($kind === 'replace_block' || $kind === 'drop_block') {
                $startMatches = $this->matchingIndexes($lines, (string) $op['start']);
                $this->assertNotSame([], $startMatches, "{$label}: start `{$op['start']}` not found in {$vendorPath}.");

                $startIndex = $startMatches[0];

                $endMatches = array_values(array_filter(
                    $this->matchingIndexes($lines, (string) $op['end']),
                    fn (int $index): bool => $index > $startIndex
                ));

                $this->assertNotSame([], $endMatches, "{$label}: no end `{$op['end']}` after start `{$op['start']}` in {$vendorPath}.");

                /** @var array<int, string> $replacement */
                $replacement = $kind === 'replace_block' ? array_values((array) $op['with']) : [];

                $lines = array_merge(
                    array_slice($lines, 0, $startIndex),
                    $replacement,
                    array_slice($lines, $endMatches[0] + 1)
                );

                continue;
            }

            if ($kind === 'insert_before' || $kind === 'insert_after') {
                $matches = $this->matchingIndexes($lines, (string) $op['anchor']);

                $this->assertCount(
                    1,
                    $matches,
                    "{$label}: anchor `{$op['anchor']}` matched ".count($matches).' times, expected exactly 1.'
                );

                $at = $matches[0] + ($kind === 'insert_after' ? 1 : 0);

                /** @var array<int, string> $inserted */
                $inserted = array_values((array) $op['lines']);

                $lines = array_merge(
                    array_slice($lines, 0, $at),
                    $inserted,
                    array_slice($lines, $at)
                );

                continue;
            }

            $this->fail("{$label}: unknown op kind `{$kind}`.");
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, int>
     */
    private function matchingIndexes(array $lines, string $pattern): array
    {
        $indexes = [];

        foreach ($lines as $index => $line) {
            $matched = str_starts_with($pattern, '/')
                ? preg_match($pattern, $line) === 1
                : $line === $pattern;

            if ($matched) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $actual
     */
    private function assertLinesIdentical(array $expected, array $actual, string $message): void
    {
        $limit = max(count($expected), count($actual));

        for ($i = 0; $i < $limit; $i++) {
            if (($expected[$i] ?? null) !== ($actual[$i] ?? null)) {
                $this->fail(
                    $message."\n"
                    .'First divergence at significant line '.($i + 1).':'
                    ."\n  mirror : ".var_export($expected[$i] ?? null, true)
                    ."\n  rebuilt: ".var_export($actual[$i] ?? null, true)
                    ."\n  (mirror: ".count($expected).' lines, rebuilt: '.count($actual).' lines)'
                );
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * The factory registration block in the JS module: from `Alpine.data('<name>'`
     * to the next `Alpine.data(` registration.
     */
    private function factoryBody(string $js, string $factory): string
    {
        $needle = "Alpine.data('{$factory}'";
        $start = strpos($js, $needle);

        $this->assertNotFalse($start, 'Alpine factory `'.$factory.'` is not registered in '.self::JS_MODULE.'.');

        $next = strpos($js, 'Alpine.data(', $start + strlen($needle));

        return substr($js, $start, $next === false ? null : $next - $start);
    }

    private function jsModule(): string
    {
        $js = file_get_contents(base_path(self::JS_MODULE));
        $this->assertIsString($js, 'cannot read '.self::JS_MODULE);

        return $js;
    }

    /**
     * @return array<int, string>
     */
    private function dataAttributes(string $body): array
    {
        preg_match_all('/(?<![\w-])data-([a-z0-9-]+)/', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function camelCase(string $kebab): string
    {
        return lcfirst(str_replace('-', '', ucwords($kebab, '-')));
    }

    private function hasRenderHeredoc(string $path): bool
    {
        $source = file_get_contents($path);

        return is_string($source) && preg_match("/<<<'(BLADE|HTML)'\n/", $source) === 1;
    }

    /**
     * The render() heredoc of a component, one trimmed line per entry, with
     * blank lines and Blade comments removed.
     *
     * Comments go because a mirror is allowed to explain itself: stripping them
     * from both sides keeps the comparison about markup, which is what actually
     * renders. `{{-- … --}}` blocks span several lines, so the scan tracks
     * whether it is inside one.
     *
     * @return array<int, string>
     */
    private function significantLines(string $path): array
    {
        $source = file_get_contents(base_path($path));

        $this->assertIsString($source, "cannot read {$path}");

        // The terminator is a line of its own (`BLADE;` / `HTML;`, indented).
        // The old pattern anchored with `$` and no `/m`, so it never matched.
        $matched = preg_match("/<<<'(BLADE|HTML)'\n(.*?)\n[ \t]*\\1;/s", $source, $heredoc);

        $this->assertSame(1, $matched, "{$path} has no render() heredoc");

        $lines = [];
        $inComment = false;

        foreach (explode("\n", $heredoc[2]) as $line) {
            $line = trim($line);

            if (! $inComment && str_starts_with($line, '{{--')) {
                $inComment = ! str_contains($line, '--}}');

                continue;
            }

            if ($inComment) {
                $inComment = ! str_contains($line, '--}}');

                continue;
            }

            if ($line === '') {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }
}
