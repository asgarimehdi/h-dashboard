<?php

namespace App\Services\Backup;

use App\Exceptions\BackupValidationException;
use Generator;
use JsonException;

/**
 * Streams the rows of one backup JSON document (issue #1418).
 *
 * A backup table is a JSON array of row objects. Decoding the whole document
 * (`json_decode()` on the file contents) is what made a restore hold the
 * entire table in PHP memory — 658 MB of the measured 694 MB peak for a
 * 200,000-row table. This tokenizer reads the stream in small chunks and
 * keeps only the object it is currently inside, so memory is
 * `CHUNK_SIZE + the largest single row`, not the table.
 *
 * It is deliberately strict about the shape the rest of the restore relies
 * on: the top level has to be an array, every element has to be an object,
 * and a truncated document is refused with the same Persian error the old
 * `decode()` produced.
 */
final class JsonArrayRowStream
{
    /**
     * How many bytes are read from the stream at a time.
     */
    private const CHUNK_SIZE = 65536;

    /**
     * @param  resource  $stream  An open, readable stream.
     * @param  string  $entry  The archive entry name, for error messages.
     */
    public function __construct(private $stream, private readonly string $entry = 'backup') {}

    /**
     * One decoded row per complete top-level object.
     *
     * @return Generator<int, array<string, mixed>>
     *
     * @throws BackupValidationException
     */
    public function rows(): Generator
    {
        $buffer = '';
        $position = 0;
        $eof = false;

        // before -> in_array -> after; inside a row: object <-> string <-> escape.
        $state = 'before';
        $depth = 0;
        $object = '';
        $index = 0;

        while (true) {
            if ($position >= strlen($buffer)) {
                if ($eof) {
                    break;
                }

                // Everything read from the previous chunk has been consumed:
                // inside an object we already appended it to `$object`.
                $buffer = '';
                $position = 0;

                $chunk = fread($this->stream, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw $this->invalidJson();
                }

                if ($chunk === '') {
                    $eof = true;

                    continue;
                }

                $buffer = $chunk;
            }

            $char = $buffer[$position++];

            if ($state === 'before') {
                if (ctype_space($char)) {
                    continue;
                }

                if ($char !== '[') {
                    throw $this->invalidJson();
                }

                $state = 'in_array';

                continue;
            }

            if ($state === 'after') {
                if (ctype_space($char)) {
                    continue;
                }

                // A second value after the array is not a valid document.
                throw $this->invalidJson();
            }

            // state === 'in_array': outside a row, only separators are allowed.
            if ($state === 'in_array') {
                if (ctype_space($char) || $char === ',') {
                    continue;
                }

                if ($char === ']') {
                    $state = 'after';

                    continue;
                }

                if ($char !== '{') {
                    throw $this->notRows();
                }

                $object = '{';
                $depth = 1;
                $state = 'object';

                continue;
            }

            // object / string / escape: the character belongs to the row.
            $object .= $char;

            if ($state === 'string') {
                if ($char === '\\') {
                    $state = 'escape';
                } elseif ($char === '"') {
                    $state = 'object';
                }

                continue;
            }

            if ($state === 'escape') {
                // Whatever follows a backslash is part of the string, even a
                // quote or another backslash.
                $state = 'string';

                continue;
            }

            // state === 'object'
            if ($char === '"') {
                $state = 'string';

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;

                continue;
            }

            if ($char === '}' || $char === ']') {
                $depth--;

                if ($depth !== 0) {
                    continue;
                }

                try {
                    /** @var mixed $decoded */
                    $decoded = json_decode($object, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw $this->invalidJson();
                }

                if (! is_array($decoded)) {
                    throw $this->notRows();
                }

                /** @var array<string, mixed> $decoded */
                yield $index++ => $decoded;

                $object = '';
                $state = 'in_array';
            }
        }

        if ($state !== 'after' || $depth !== 0) {
            throw $this->invalidJson();
        }
    }

    private function invalidJson(): BackupValidationException
    {
        return new BackupValidationException("محتوای «{$this->entry}» در فایل بک‌آپ JSON معتبر نیست.");
    }

    private function notRows(): BackupValidationException
    {
        return new BackupValidationException("محتوای «{$this->entry}» باید آرایه‌ای از ردیف‌ها باشد.");
    }
}
