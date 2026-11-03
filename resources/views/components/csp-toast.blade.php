{{-- #1281: app-owned copy of MaryUI 2.9.11's Toast — markup identical to
     vendor/robsontenorio/mary/src/View/Components/Toast.php, with a per-request
     CSP nonce on the inline <script> (the enforcing fact that forced the copy:
     nonce attributes cannot cross a vendor class-component heredoc).
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

    <script nonce="{{ Vite::cspNonce() }}">
        window.toast = function(payload){
            window.dispatchEvent(new CustomEvent('mary-toast', {detail: payload}))
        }

        document.addEventListener('livewire:init', () => {
            Livewire.hook('request', ({fail}) => {
                fail(({status, content, preventDefault}) => {
                    try {
                        let result = JSON.parse(content);

                        if (result?.toast && typeof window.toast === "function") {
                            window.toast(result);
                        }

                        if ((result?.prevent_default ?? false) === true) {
                            preventDefault();
                        }
                    } catch (e) {
                        console.log(e)
                    }
                })
            })
        })
    </script>
    @endpersist
</div>
