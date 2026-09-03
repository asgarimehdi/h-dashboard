<?php

use App\View\Components\AppBrand;
use Tests\TestCase;

covers(AppBrand::class);

uses(TestCase::class);

test('app brand component class exists', function () {
    expect(new AppBrand)->toBeInstanceOf(AppBrand::class);
});
