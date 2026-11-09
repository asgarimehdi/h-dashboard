{{-- #1281: app-owned copy of MaryUI 2.9.11's Toast — markup identical to
     vendor/robsontenorio/mary/src/View/Components/Toast.php, with a per-request
     CSP nonce on the inline <script> (the enforcing fact that forced the copy:
     nonce attributes cannot cross a vendor class-component heredoc).
     #1377: that inline script is gone — `toast` now lives on the layout-level
     Alpine.data `globalToast` (resources/js/app-components.js), reachable from
     any Livewire component's `$this->js('toast({...})')` via the CSP
     evaluator's ancestor scope walk (livewire.csp.js:5311-5322). `window.toast`
     is undefined BY DESIGN.
     Upgrade condition: if Mary ever learns a `nonce` pass-through, delete this
     file and repoint <x-csp-toast/> to <x-toast/>. --}}
@props(['position' => 'toast-top toast-end'])
<div>
    @persist('mary-toaster')
    <div
        x-cloak
        x-data="cspToast"
    >
        <div
            class="toast !whitespace-normal rounded-box fixed cursor-pointer z-[999] overflow-hidden"
            :class="toast.position || '{{ $position }}'"
            x-show="show"
            @mouseenter="pause()"
            @mouseleave="resume()"
            x-classes="alert alert-success alert-warning alert-error alert-info top-10 end-10 toast toast-top toast-bottom toast-center toast-end toast-middle toast-start"
            @click="dismiss()"
        >
            <div class="alert gap-2" :class="toast.css">
                {{-- D3: the icon is static markup — x-html is prohibited in the
                     CSP build, and the toast type is already carried by
                     toast.css (alert-success/warning/error/info). --}}
                <div class="hidden sm:inline-block"><x-mary-icon name="o-information-circle" class="w-6 h-6" /></div>
                <div class="grid">
                    <div x-text="toast.title" class="font-bold"></div>
                    <div x-text="toast.description" class="text-xs"></div>
                </div>
            </div>
            <progress
                x-show="!toast.noProgress"
                class="-mt-3 h-1 w-full progress"
                :class="toast.progressClass"
                :max="maxProgress"
                :value="progress">
            </progress>
        </div>
    </div>

    @endpersist
</div>
