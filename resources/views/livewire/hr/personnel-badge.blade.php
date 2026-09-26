{{--
    Personnel badge for the reusable unit tree (#704).

    Rendered by `unit.tree` for every node; receives:
      $unit      — the Unit being rendered
      $badgeData — per-node data passed down from the page (personnel counts
                   keyed by unit id, computed once by hr.org-chart)
--}}
@php
    $count = $badgeData[$unit->id] ?? 0;
@endphp

<span class="badge badge-sm badge-ghost">{{ $count }} نفر</span>
@if($count === 0)
    <span class="badge badge-sm badge-error">خالی</span>
@endif
