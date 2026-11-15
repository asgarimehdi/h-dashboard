{{--
    Issue #1532 — the ONE auth-failure banner every cookie-authenticated API
    consumer shows. `authBanner` (resources/js/api-fetch.js) flips `authFailed`
    when `apiFetch()` sees a 401/403, and the latch stops every later request:
    retrying a cookie-authenticated call on an untrusted host cannot succeed.

    Call sites: the GIS dashboard, /it/networks and /it/wireless. Include it
    once per page — never per layer, chart or gauge. Pass `message` to name the
    page's data; the default is the shared wording.
--}}
@props(['message' => null])

<div x-data="authBanner"
     x-show="authFailed"
     x-cloak
     data-testid="api-auth-banner"
     role="alert"
     {{ $attributes->merge(['class' => 'api-auth-banner alert alert-warning shadow-lg text-sm']) }}>
    <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
    <span>{{ $message ?? 'دسترسی به داده‌ها برقرار نشد؛ دوباره وارد شوید.' }}</span>
    <a href="{{ route('login') }}" class="btn btn-sm btn-primary">ورود</a>
</div>
