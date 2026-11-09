<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;

/**
 * Table with the accessibility affordances maryUI does not ship (#957, step 2).
 *
 * WHY THIS EXISTS
 * ---------------
 * `<x-table>` is a maryUI component, so its markup lives in vendor/ and cannot be
 * edited from the app. Its sortable header rendered as a bare `<th>` with an
 * `@click` and nothing else: no `aria-sort`, so no screen reader ever said
 * "sorted ascending", and no `tabindex`, so the sort control was unreachable by
 * keyboard at all. The `<th>` is inside maryUI's own `render()` heredoc and
 * receives no `$attributes`, so there is no call-site change that can add these —
 * the only faithful fix is an app-owned component registered over the alias.
 *
 * WHAT IS AND IS NOT MIRRORED
 * ---------------------------
 * `render()` is a verbatim copy of `Mary\View\Components\Table::render()` with exactly
 * one difference: the sortable `<th>` gains `aria-sort`, `tabindex="0"`, and the
 * two `@keydown` bindings. Everything else — `x-data`, `wire:key`s, `@scope`
 * slots, expandable/selectable branches, pagination — is untouched on purpose, so
 * the 15 call sites behave exactly as before.
 *
 * MAINTENANCE
 * -----------
 * Re-sync this file against `vendor/robsontenorio/mary/src/View/Components/Table.php`
 * after any `robsontenorio/mary` upgrade, and keep the `mary-active-menu`-style
 * coupling in mind for sibling components. AccessibilityInfrastructureTest pins
 * both the new contract and the sort payload the click handler still sends.
 *
 * Registered in AppServiceProvider::boot(); a deployment must clear the compiled
 * Blade cache (`php artisan view:cache` after `view:clear`) or the alias change
 * will not reach already-compiled views.
 */
/**
 * MaryUI Table with CSP-safe selection state (#1352), building on
 * AccessibleTable (#957).
 *
 * WHAT IS AND IS NOT MIRRORED
 * ---------------------------
 * `render()` is a verbatim copy of `AccessibleTable::render()` —
 * including the `aria-sort`/`tabindex`/`@keydown` header affordances
 * — with exactly three differences: the `@entangle` `x-data` literal
 * becomes `x-data="maryTable"` (the selection is synced manually
 * from `$wire` in `resources/js/mary-components.js`, because
 * directive-expression entangle evaluates to `undefined` under the
 * CSP build); and the two `||` class expressions become ternaries
 * (`||` in a directive is outside the proven subset). `ariaSort()`
 * is inherited, not duplicated.
 *
 * MAINTENANCE
 * -----------
 * Re-sync against AccessibleTable/vendor Table.php after any
 * `robsontenorio/mary` upgrade. Registered in
 * AppServiceProvider::boot() (replacing the AccessibleTable alias).
 */
class CspTable extends AccessibleTable
{
    public function render(): View|Closure|string
    {
        return <<<'HTML'
                <div x-data="maryTable"
                    data-model="{{ $attributes->wire('model') ? $attributes->wire('model')->value() : '' }}"
                    data-selectable="{{ json_encode($selectable) }}"
                    data-page-ids="{{ json_encode($getAllIds()) }}"
                >
                <div class="{{ $containerClass }}" x-classes="overflow-x-auto">
                <table
                        {{
                            $attributes
                                ->whereDoesntStartWith('wire:model')
                                ->class([
                                    'table',
                                    'table-zebra' => $striped,
                                    '[&_tr:nth-child(4n+3)]:bg-base-200' => $striped && $expandable,
                                    'cursor-pointer' => $attributes->hasAny(['@row-click', 'link'])
                                ])
                        }}
                    >
                        <!-- HEADERS -->
                        <thead @class(["text-base-content", "hidden" => $noHeaders])>
                            <tr x-ref="headers">
                                <!-- CHECKALL -->
                                @if($selectable)
                                    <th class="w-1" wire:key="{{ $uuid }}-checkall-{{ implode(',', $getAllIds()) }}">
                                        <input
                                            id="checkAll-{{ $uuid }}"
                                            type="checkbox"
                                            class="checkbox checkbox-sm"
                                            x-ref="mainCheckbox"
                                            x-bind:disabled="pageIds.length === 0"
                                            @click="toggleCheckAll($el.checked)" />
                                    </th>
                                @endif

                                <!-- EXPAND EXTRA HEADER -->
                                @if($expandable)
                                    <th class="w-1"></th>
                                 @endif

                                @foreach($headers as $header)
                                     @php
                                        # SKIP THE HIDDEN COLUMN
                                        if($isHidden($header)) continue;

                                        # Scoped slot`s name like `user.city` are compiled to `user___city` through `@scope / @endscope`.
                                        # So we use current `$header` key  to find that slot on context.
                                        $temp_key = str_replace('.', '___', $header['key'])
                                    @endphp

                                    <th
                                        class="@if($isSortable($header)) cursor-pointer hover:bg-base-200 @endif {{ $header['class'] ?? ' ' }}"

                                        @if($sortBy && $isSortable($header))
                                            aria-sort="{{ $ariaSort($header) }}"
                                            tabindex="0"
                                            @click="$wire.set('{{ $sortByProperty }}', {column: '{{ $getSort($header)['column'] }}', direction: '{{ $getSort($header)['direction'] }}' })"
                                            {{-- Enter/Space mirror the click: a <th> is focusable but a
                                                 <th> is not a button, so without these two bindings
                                                 keyboard users could focus a sort control and do
                                                 nothing with it. --}}
                                            @keydown.enter.prevent="$wire.set('{{ $sortByProperty }}', {column: '{{ $getSort($header)['column'] }}', direction: '{{ $getSort($header)['direction'] }}' })"
                                            @keydown.space.prevent="$wire.set('{{ $sortByProperty }}', {column: '{{ $getSort($header)['column'] }}', direction: '{{ $getSort($header)['direction'] }}' })"
                                        @endif
                                    >
                                        {{ isset(${"header_".$temp_key}) ? ${"header_".$temp_key}($header) : $header['label'] }}

                                        @if($isSortable($header))
                                            <x-mary-icon :name="$isSortedBy($header) ? $getSort($header)['direction'] == 'asc' ? 'o-chevron-down' : 'o-chevron-up' : 'o-chevron-up-down'"  class="size-3! mb-1 ms-1" />
                                        @endif
                                    </th>
                                @endforeach

                                <!-- ACTIONS (Just a empty column) -->
                                @if($actions)
                                    <th class="w-1"></th>
                                @endif
                            </tr>
                        </thead>

                        <!-- ROWS -->
                        <tbody>
                            @foreach($rows as $k => $row)
                                <tr
                                    wire:key="{{ $uuid }}-{{ $k }}"
                                    @class([$rowClasses($row), "hover:bg-base-200" => !$noHover])
                                    @if($attributes->has('@row-click'))
                                        @click="$dispatch('row-click', {{ json_encode($row) }});"
                                    @endif
                                >
                                    <!-- CHECKBOX -->
                                    @if($selectable)
                                        <td class="w-1">
                                            <input
                                                id="checkbox-{{ $uuid }}-{{ $k }}"
                                                type="checkbox"
                                                class="checkbox checkbox-sm"
                                                value="{{ data_get($row, $selectableKey) }}"
                                                x-model{{ $selectableModifier() }}="selection"
                                                @click.stop="toggleCheck($el.checked, {{ json_encode($row) }})" />
                                        </td>
                                    @endif

                                    <!-- EXPAND ICON -->
                                    @if($expandable)
                                        <td class="w-1 pe-0 py-0">
                                            @if(data_get($row, $expandableCondition))
                                                <x-mary-icon
                                                    name="o-chevron-down"
                                                    ::class="isExpanded({{ $getKeyValue($row, 'expandableKey') }}) ? '' : 'ltr:-rotate-90 rtl:rotate-90 !text-current'"
                                                    class="cursor-pointer p-2 w-8 h-8 bg-base-300 rounded-lg"
                                                    @click="toggleExpand({{ $getKeyValue($row, 'expandableKey') }});" />
                                            @endif
                                        </td>
                                     @endif

                                    <!--  ROW VALUES -->
                                    @foreach($headers as $header)
                                        @php
                                            # SKIP THE HIDDEN COLUMN
                                            if($isHidden($header)) continue;

                                            # Scoped slot`s name like `user.city` are compiled to `user___city` through `@scope / @endscope`.
                                            # So we use current `$header` key  to find that slot on context.
                                            $temp_key = str_replace('.', '___', $header['key'])
                                        @endphp

                                        <!--  HAS CUSTOM SLOT ? -->
                                        @if(isset(${"cell_".$temp_key}))
                                            <td @class([$cellClasses($row, $header), "p-0" => $hasLink($header)])>
                                                @if($hasLink($header))
                                                    <a href="{{ $redirectLink($row) }}" wire:navigate class="block py-3 px-4">
                                                @endif

                                                {{ ${"cell_".$temp_key}($fluent ? fluent($row) : $row) }}

                                                @if($hasLink($header))
                                                    </a>
                                                 @endif
                                            </td>
                                        @else
                                            <td @class([$cellClasses($row, $header), "p-0" => $hasLink($header)])>
                                                @if($hasLink($header))
                                                    <a href="{{ $redirectLink($row) }}" wire:navigate class="block py-3 px-4">
                                                @endif

                                                {{ $format($row, data_get($row, $header['key']), $header) }}

                                                @if($hasLink($header))
                                                    </a>
                                                @endif
                                            </td>
                                        @endif
                                    @endforeach

                                    <!-- ACTIONS -->
                                    @if($actions)
                                        <td class="text-right py-0">{{ $actions($row) }}</td>
                                    @endif
                                </tr>

                                <!-- EXPANSION SLOT -->
                                @if($expandable)
                                    <tr wire:key="{{ $uuid }}-{{ $k }}--expand" class="!bg-inherit" :class="isExpanded({{ $getKeyValue($row, 'expandableKey') }}) ? '' : 'hidden'">
                                        <td :colspan="colspanSize">
                                            {{ $expansion($fluent ? fluent($row) : $row) }}
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>

                        <!-- FOOTER SLOT -->
                        @isset ($footer)
                            <tfoot {{ $footer->attributes ?? '' }}>
                                {{ $footer }}
                            </tfoot>
                        @endisset
                    </table>

                    @if(count($rows) === 0)
                        @if($showEmptyText)
                            <div class="text-center py-4 text-base-content/50">
                                {{ $emptyText }}
                            </div>
                        @endif
                        @if($empty)
                            <div class="text-center py-4 text-base-content/50">
                                {{ $empty }}
                            </div>
                        @endif
                    @endif
                </div>
                    <!-- Pagination -->
                    @if($withPagination)
                        @if($perPage)
                            <x-mary-pagination :rows="$rows" :per-page-values="$perPageValues" wire:model.live="{{ $perPage }}" />
                        @else
                            <x-mary-pagination :rows="$rows" :per-page-values="$perPageValues" />
                        @endif
                    @endif
                </div>
                HTML;
    }
}
