<?php

namespace Tests\Feature;

use App\Models\Unit;
use App\Services\AccessService;
use App\Services\UnitTreeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\InteractsWithTestSetup;
use Tests\TestCase;

covers(UnitTreeService::class);

uses(TestCase::class, RefreshDatabase::class, InteractsWithTestSetup::class);

/**
 * Issue #704 — the tree queries that `unit.tree` and OrgChartController share.
 * Every method takes the accessible ids explicitly, so each test passes its
 * own scope instead of depending on session state.
 */
beforeEach(function () {
    ['user' => $this->user, 'unit' => $this->unit] = $this->createUserWithUnit();
    // AccessService resolves the scope from the authenticated user.
    $this->actingAs($this->user);

    // In scope (scope root is $this->unit): unit -> mid -> leaf, plus hiddenChild
    $this->mid = Unit::create(['name' => 'میانی', 'parent_id' => $this->unit->id]);
    $this->leaf = Unit::create(['name' => 'برگ', 'parent_id' => $this->mid->id]);
    $this->hiddenChild = Unit::create(['name' => 'پنهان', 'parent_id' => $this->unit->id]);

    // Out of scope: an unrelated root the user may never see.
    $this->outside = Unit::create(['name' => 'بیرون از دامنه']);

    $this->scope = app(AccessService::class)->accessibleUnitIds();
    expect($this->scope)->not->toBeEmpty();

    $this->service = app(UnitTreeService::class);
});

test('roots returns the scope root and nothing hanging below it', function () {
    $roots = $this->service->roots($this->scope)->get();

    expect($roots->pluck('id')->all())->toBe([$this->unit->id]);
});

test('roots never lists units outside the scope', function () {
    $roots = $this->service->roots($this->scope)->get();

    expect($roots->pluck('id')->all())->not->toContain($this->outside->id);
});

test('roots makes a scope-rooted child the root when its parent is not accessible', function () {
    // Scope starting deeper: the parent above is unreachable, but the child
    // must still render as a root instead of disappearing.
    $roots = $this->service->roots([(int) $this->mid->id])->get();

    expect($roots->pluck('id')->all())->toBe([$this->mid->id]);
});

test('roots eager-loads unitType so rendering the tree does not N+1', function () {
    $root = $this->service->roots($this->scope)->get()->first();

    expect($root->relationLoaded('unitType'))->toBeTrue();
});

test('childrenOf returns the direct children inside the scope', function () {
    $children = $this->service->childrenOf((int) $this->unit->id, $this->scope)->get();

    expect($children->pluck('id')->all())
        ->toContain($this->mid->id, $this->hiddenChild->id)
        ->not->toContain($this->outside->id);
});

test('childrenOf hides children excluded by the scope', function () {
    $children = $this->service->childrenOf((int) $this->unit->id, [(int) $this->unit->id, (int) $this->mid->id])->get();

    expect($children->pluck('id')->all())->toBe([$this->mid->id]);
});

test('childrenOf eager-loads unitType', function () {
    $child = $this->service->childrenOf((int) $this->unit->id, $this->scope)->get()->first();

    expect($child->relationLoaded('unitType'))->toBeTrue();
});

test('childrenOfMany loads several levels in one query and stays in scope', function () {
    $children = $this->service->childrenOfMany(
        [(int) $this->unit->id, (int) $this->mid->id],
        $this->scope
    )->get();

    expect($children->pluck('id')->all())
        ->toContain($this->mid->id, $this->hiddenChild->id, $this->leaf->id)
        ->not->toContain($this->outside->id);
});

test('childrenOfMany returns nothing for an empty node list', function () {
    expect($this->service->childrenOfMany([], $this->scope)->get())->toBeEmpty();
});

test('search matches unit names inside the scope', function () {
    $hits = $this->service->search('برگ', $this->scope)->get();

    expect($hits->pluck('id')->all())->toBe([$this->leaf->id]);
});

test('search never returns units outside the scope', function () {
    expect($this->service->search('بیرون از دامنه', $this->scope)->get())->toBeEmpty();
});

test('search eager-loads the parent relation used to expand ancestors', function () {
    $hit = $this->service->search('برگ', $this->scope)->get()->first();

    expect($hit->relationLoaded('parent'))->toBeTrue();
});

test('unitsInScope returns every accessible unit with its personnel count', function () {
    $units = $this->service->unitsInScope($this->scope)->get();

    expect($units->pluck('id')->all())
        ->toContain($this->unit->id, $this->mid->id, $this->leaf->id)
        ->not->toContain($this->outside->id)
        ->and($units->first()->personnel_count)->toBeInt();
});

test('subtree returns the descendants of a unit inside the scope', function () {
    $ids = $this->service->subtree((int) $this->unit->id, $this->scope)->get()->pluck('id')->all();

    expect($ids)->toContain($this->unit->id, $this->mid->id, $this->leaf->id)
        ->not->toContain($this->outside->id);
});

test('subtree returns nothing for a unit outside the scope', function () {
    expect($this->service->subtree((int) $this->outside->id, [(int) $this->mid->id])->get())->toBeEmpty();
});
