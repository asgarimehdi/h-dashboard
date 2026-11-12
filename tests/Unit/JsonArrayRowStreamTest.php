<?php

use App\Exceptions\BackupValidationException;
use App\Services\Backup\JsonArrayRowStream;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A read-only stream carrying the given JSON document.
 *
 * @return resource
 */
function jsonRowStream(string $json)
{
    $stream = fopen('php://temp', 'r+b');

    if ($stream === false) {
        throw new RuntimeException('Cannot open the in-memory stream.');
    }

    fwrite($stream, $json);
    rewind($stream);

    return $stream;
}

test('yields one row per top-level object', function () {
    $json = <<<'JSON'
        [
            {"id":1,"nested":{"closing":"}"},"list":[1,2,{"closing":"]"}],"quote":"a \"quoted\" } value","slash":"a \\ backslash","unicode":"سلام دنیا","empty":[]},
            {"id":2,"text":"{}"},
            {"id":3}
        ]
        JSON;

    $rows = iterator_to_array((new JsonArrayRowStream(jsonRowStream($json), 'users.json'))->rows(), false);

    expect($rows)->toHaveCount(3);
    expect($rows[0]['id'])->toBe(1);
    expect($rows[0]['nested'])->toBe(['closing' => '}']);
    expect($rows[0]['list'])->toBe([1, 2, ['closing' => ']']]);
    expect($rows[0]['quote'])->toBe('a "quoted" } value');
    expect($rows[0]['slash'])->toBe('a \\ backslash');
    expect($rows[0]['unicode'])->toBe('سلام دنیا');
    expect($rows[0]['empty'])->toBe([]);
    expect($rows[1])->toBe(['id' => 2, 'text' => '{}']);
    expect($rows[2])->toBe(['id' => 3]);
});

test('yields a single row', function () {
    $rows = iterator_to_array((new JsonArrayRowStream(jsonRowStream('[{"id":7}]'), 'users.json'))->rows(), false);

    expect($rows)->toBe([['id' => 7]]);
});

test('yields nothing for an empty array', function () {
    $rows = iterator_to_array((new JsonArrayRowStream(jsonRowStream('[]'), 'users.json'))->rows(), false);

    expect($rows)->toBe([]);
});

test('rejects truncated json', function (string $json) {
    expect(fn () => iterator_to_array((new JsonArrayRowStream(jsonRowStream($json), 'users.json'))->rows()))
        ->toThrow(BackupValidationException::class, 'JSON معتبر نیست');
})->with([
    'missing closing bracket' => '[{"id":1}',
    'cut inside an object' => '[{"id":1',
    'cut inside a string' => '[{"id":"abc',
    'no array at all' => '',
]);

test('rejects a non-object top-level element', function (string $json) {
    expect(fn () => iterator_to_array((new JsonArrayRowStream(jsonRowStream($json), 'users.json'))->rows()))
        ->toThrow(BackupValidationException::class, 'آرایه‌ای از ردیف‌ها');
})->with([
    'number' => '[1]',
    'array' => '[[{"id":1}]]',
    'string' => '["row"]',
    'object followed by number' => '[{"id":1},2]',
]);
