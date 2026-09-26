{{-- Personnel badge plug-in for <livewire:unit.tree badge-view="...">.
     Receives $unit from the tree node. --}}
@props(['unit'])

@php
    $count = $unit->personnel_count ?? 0;
@endphp

<span class="badge badge-sm badge-ghost">{{ $count }} نفر</span>
@if ($count === 0)
    <span class="badge badge-sm badge-error">خالی</span>
@endif
