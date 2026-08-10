<?php

namespace Tests\Feature;

use App\Traits\PersianNormalizer;
use Tests\TestCase;

class NormalizerHelper
{
    use PersianNormalizer;
}

uses(TestCase::class);

test('persian normalizer handles additional mixed characters', function () {
    $helper = new NormalizerHelper();

    expect($helper->normalize('ي و ك'))->toBe('ی و ک');
    expect($helper->normalize('محمدی‌پور'))->toBe('محمدی پور');
});