<?php

use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

/**
 * #1469 §1 — a `wire:click` expression must be exactly ONE statement.
 *
 * The CSP-safe Alpine build parses directive expressions with a restricted
 * parser that accepts a single expression. A second statement after `;` is
 * rejected with `Unexpected token: …`, and the parser rejects the WHOLE
 * directive — neither statement runs, so the element silently does nothing
 * (this is how the help button shipped: `$dispatch(...); $set(...)` sent no
 * request and never opened the modal).
 *
 * The fix for each such directive is a separate `x-on:` for the second
 * effect, never a second statement in the same attribute.
 *
 * Scope: `wire:click` only. `@click` / `x-on:click` keep their own parser
 * question (#1469 deliberately excludes them) — do not widen this guard
 * without the confirmation that the Alpine parser behaves the same way.
 *
 * Comments are stripped first: the directive and its failure mode are quoted
 * verbatim in a few docblocks, and a guard that flags its own documentation
 * goes red the first time someone edits those comments.
 */
test('no blade view packs two statements into one wire:click', function () {
    /** @var array<int, string> $offenders */
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $contents = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
        $contents = (string) preg_replace('/<!--.*?-->/s', '', $contents);

        preg_match_all(
            '/wire:click(?:\.[a-z]+)*\s*=\s*("([^"]*)"|\'([^\']*)\')/i',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $expression = $match[2] !== '' ? $match[2] : $match[3];

            if (! str_contains($expression, ';')) {
                continue;
            }

            $relative = str_replace(resource_path().DIRECTORY_SEPARATOR, '', $file->getPathname());

            $offenders[] = $relative.' -> wire:click="'.$expression.'"';
        }
    }

    expect($offenders)->toBe(
        [],
        'A wire:click holds more than one statement; the CSP parser accepts one expression and drops the whole directive. Move the second effect to its own attribute.'
    );
});
