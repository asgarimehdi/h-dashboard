<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class AppBrand extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return <<<'HTML'
                <a href="/" wire:navigate>
                    <style>
                        .app-brand-icon {
                            background: linear-gradient(135deg, #6366f1, #06b6d4);
                        }
                        [data-theme="synthwave"] .app-brand-icon {
                            background: linear-gradient(135deg, #a78bfa, #22d3ee);
                        }
                        .app-brand-text {
                            background: linear-gradient(to right, #6366f1, #06b6d4);
                            -webkit-background-clip: text;
                            -webkit-text-fill-color: transparent;
                            background-clip: text;
                        }
                        [data-theme="synthwave"] .app-brand-text {
                            background: linear-gradient(to right, #a78bfa, #22d3ee);
                            -webkit-background-clip: text;
                            -webkit-text-fill-color: transparent;
                            background-clip: text;
                        }
                    </style>

                    <!-- Hidden when collapsed -->
                    <div {{ $attributes->class(["hidden-when-collapsed"]) }}>
                        <div class="flex items-center gap-2">
                            <div class="app-brand-icon w-8 h-8 rounded-xl flex items-center justify-center">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="w-5 h-5" fill="none">
                                    <rect x="24" y="12" width="16" height="40" rx="4" fill="white" opacity="0.95"/>
                                    <rect x="12" y="24" width="40" height="16" rx="4" fill="white" opacity="0.95"/>
                                    <path d="M32 44 C32 44, 22 36, 22 30 C22 26, 24.5 24, 27 24 C28.5 24, 30 25, 32 27 C34 25, 35.5 24, 37 24 C39.5 24, 42 26, 42 30 C42 36, 32 44, 32 44 Z" fill="white" opacity="0.5"/>
                                </svg>
                            </div>
                            <span class="app-brand-text font-bold text-xl me-2 whitespace-nowrap">
                                داشبورد سلامت
                            </span>
                        </div>
                    </div>

                    <!-- Display when collapsed -->
                    <div class="display-when-collapsed hidden mx-5 mt-5 mb-1 h-[28px]">
                        <div class="app-brand-icon w-7 h-7 rounded-lg flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="w-4 h-4" fill="none">
                                <rect x="24" y="12" width="16" height="40" rx="4" fill="white" opacity="0.95"/>
                                <rect x="12" y="24" width="40" height="16" rx="4" fill="white" opacity="0.95"/>
                                <path d="M32 44 C32 44, 22 36, 22 30 C22 26, 24.5 24, 27 24 C28.5 24, 30 25, 32 27 C34 25, 35.5 24, 37 24 C39.5 24, 42 26, 42 30 C42 36, 32 44, 32 44 Z" fill="white" opacity="0.5"/>
                            </svg>
                        </div>
                    </div>
                </a>
            HTML;
    }
}
