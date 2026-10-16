<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Mary\View\Components\MenuItem;

/**
 * Menu item that also states the current page to assistive technology (#957, step 2).
 *
 * WHY THIS EXISTS
 * ---------------
 * `<x-menu activate-by-route>` in components/layouts/app.blade.php marks the item
 * for the current route with a CSS class (`mary-active-menu`) and nothing else.
 * That is a colour and a background — invisible to a screen reader, which then
 * reports the sidebar as an undifferentiated list of links with no indication of
 * where the user actually is.
 *
 * The condition below is deliberately the SAME expression the class uses, so the
 * visual and the announced state cannot drift apart.
 *
 * MAINTENANCE
 * -----------
 * `mary-active-menu` must be preserved. Mary's MenuSub decides a submenu is open
 * with `Str::contains($slot, 'mary-active-menu')` over the already-rendered child
 * markup — remove the class and every submenu silently collapses.
 *
 * Re-sync render() against vendor/robsontenorio/mary/src/View/Components/MenuItem.php
 * after any robsontenorio/mary upgrade. Registered in AppServiceProvider::boot();
 * deploys must clear the compiled Blade cache for the alias change to take effect.
 */
class AccessibleMenuItem extends MenuItem
{
    /**
     * #996 — match on PATH SEGMENTS, not on raw strings.
     *
     * maryUI's own implementation is
     * `! $this->exact && $this->link != '/' && Str::startsWith($route, $link)`
     * (vendor/robsontenorio/mary .../MenuItem.php:73). A string prefix is not a
     * path prefix: `/map` "starts" `/maps/point`, and `/maps/route` "starts"
     * `/maps/route2`, so two or three items were highlighted at once.
     *
     * Two more rules the vendor version cannot express:
     *
     * 1. A link that carries a query string (`/tickets/new?category=bug`) is an
     *    action shortcut, not the menu entry that identifies a page — the page it
     *    opens is `/tickets/new`. `request()->url()` strips the query, so such a
     *    link could never match anyway; saying so explicitly keeps one page =
     *    one highlighted link instead of relying on that accident.
     * 2. The query string of the CURRENT request is ignored. Livewire keeps page
     *    state in the URL (`#[Url]` on ⚡inbox / ⚡monitoring / map-dashboard), so
     *    matching the full URL would blank the highlight on `/users?page=2`.
     *
     * `exact` therefore means, in this component: match this path only, never a
     * child path. `/users/:changepassword` has its own menu entry, so `/users`
     * sets it; `/units` does not (its children — `/units/chart`,
     * `/units/{id}/map` — have no entry and must keep the parent highlighted).
     */
    public function routeMatches(): bool
    {
        $href = $this->getHref();

        if ($href === null) {
            return false;
        }

        if ($this->route) {
            return request()->routeIs($this->route);
        }

        $link = url($this->link ?? '');
        $route = url(request()->url());

        if ($link == $route) {
            return true;
        }

        $linkQuery = parse_url($link, PHP_URL_QUERY);

        if (is_string($linkQuery) && $linkQuery !== '') {
            return false;
        }

        $linkPath = (string) (parse_url($link, PHP_URL_PATH) ?: '/');
        $routePath = (string) (parse_url($route, PHP_URL_PATH) ?: '/');

        if ($linkPath === $routePath) {
            return true;
        }

        return ! $this->exact
            && $linkPath !== '/'
            && Str::startsWith($routePath, rtrim($linkPath, '/').'/');
    }

    public function render(): View|Closure|string
    {
        if ($this->hidden === true) {
            return '';
        }

        return <<<'BLADE'
                @aware(['horizontal' => false, 'activateByRoute' => false, 'activeBgColor' => 'bg-base-300'])

                <li @class(['menu-disabled' => $disabled])>
                    <a
                        {{
                            $attributes->class([
                                "my-0.5 py-1.5 px-4 hover:text-inherit whitespace-nowrap",
                                "mary-active-menu $activeBgColor" => ($active || ($activateByRoute && $routeMatches()))
                            ])
                        }}

                        {{-- #957: `mary-active-menu` سیگنال بصری است؛ بدون aria-current
                             صفحه‌خوان نمی‌فهمد کدام صفحه در حال حاضر باز است. شرط عیناً
                             همان شرطِ کلاس بالاست تا این دو هرگز واگرا نشوند.
                             `$getHref()` لازم است: آیتمِ بدون لینک (دکمهٔ جمع‌کردن
                             سایدبار) صفحه‌ای را «فعلی» نمی‌کند. --}}
                        @if($getHref() && ($active || ($activateByRoute && $routeMatches())))
                            aria-current="page"
                        @endif

                        @if($getHref())
                            href="{{ $getHref() }}"

                            @if($external)
                                target="_blank"
                            @endif

                            @if(!$external && !$noWireNavigate)
                                {{ $attributes->wire('navigate')->value() ? $attributes->wire('navigate') : 'wire:navigate' }}
                            @endif
                        @endif

                        @if($spinner)
                            wire:target="{{ $spinnerTarget() }}"
                            wire:loading.attr="disabled"
                        @endif
                    >
                        {{-- SPINNER --}}
                        @if($spinner)
                            <span wire:loading wire:target="{{ $spinnerTarget() }}" class="loading loading-spinner loading-xs w-5 h-5 @if($icon) my-1 @endif"></span>
                        @endif

                        @if($icon)
                            <span class="block py-0.5" @if($spinner) wire:loading.class="hidden" wire:target="{{ $spinnerTarget() }}" @endif>
                                <x-mary-icon :name="$icon" @class(['mb-0.5', $iconClasses]) />
                            </span>
                        @endif

                        @if($title || $slot->isNotEmpty())
                        <span @class(["mary-hideable whitespace-nowrap", "truncate" => !$horizontal])>
                            @if($title)
                                {{ $title }}

                                @if($badge)
                                    <span class="badge badge-sm {{ $badgeClasses }}">{{ $badge }}</span>
                                @endif
                            @else
                                {{ $slot }}
                            @endif
                        </span>
                        @endif
                    </a>
                </li>
                BLADE;
    }
}
