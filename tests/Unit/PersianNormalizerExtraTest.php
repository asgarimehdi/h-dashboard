<?php

use App\Traits\PersianNormalizer;
use Tests\TestCase;

class NormalizerHelper2
{
    use PersianNormalizer;
}

uses(TestCase::class);

test('persian normalizer normalizes additional mixed characters', function () {
    $helper = new NormalizerHelper2();

    expect($helper->normalize('ي و ك'))->toBe('ی و ک');
    expect($helper->normalize('محمدی‌پور'))->toBe('محمدی پور');
});
