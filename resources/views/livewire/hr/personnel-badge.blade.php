{{--
    Personnel badge for a unit node in the shared `unit.tree` component.

    Rendered per node by unit/tree-node via the `badgeView` prop, so the tree
    stays free of any HR knowledge. Receives `$unit` from the node template;
    `$badgeData` is the unit-id => payload map the consuming page passes down.

    A second feature reusing the tree (e.g. covered population per unit) passes
    its own badge view and never touches this file.
--}}
@props(['unit', 'badgeData' => []])

<span class="badge badge-sm badge-ghost">{{ $badgeData[$unit->id] ?? 0 }} نفر</span>
@if (($badgeData[$unit->id] ?? 0) === 0)
    <span class="badge badge-sm badge-error">خالی</span>
@endif
