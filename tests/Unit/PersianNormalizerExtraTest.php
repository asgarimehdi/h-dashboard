<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Unit\NormalizerHelper;

covers(\App\Traits\PersianNormalizer::class);

uses(TestCase::class);

test('persian normalizer handles additional mixed characters', function () {
    $helper = new NormalizerHelper();

    expect($helper->normalize('ي و ك'))->toBe('ی و ک');
    expect($helper->normalize('محمدی‌پور'))->toBe('محمدی پور');
});