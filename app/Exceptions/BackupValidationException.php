<?php

namespace App\Exceptions;

use App\Support\Backup\BackupProblem;
use RuntimeException;

/**
 * The backup file cannot be restored — it is broken, or it would break the
 * database (issue #1375).
 *
 * The exception carries every problem that was found, so the caller can
 * report them one by one; the message is the single Persian sentence the
 * user sees.
 */
class BackupValidationException extends RuntimeException
{
    /**
     * How many problems the message spells out before it only counts the rest.
     */
    private const MESSAGE_LIMIT = 5;

    /**
     * @param  list<BackupProblem>  $problems
     */
    public function __construct(string $message, public readonly array $problems = [])
    {
        parent::__construct($message);
    }

    /**
     * @param  list<BackupProblem>  $problems
     */
    public static function fromProblems(array $problems): self
    {
        return new self(self::describe($problems), $problems);
    }

    /**
     * @param  list<BackupProblem>  $problems
     */
    private static function describe(array $problems): string
    {
        $lines = [];

        foreach (array_slice($problems, 0, self::MESSAGE_LIMIT) as $problem) {
            $lines[] = match ($problem->kind) {
                BackupProblem::KIND_UNKNOWN_COLUMN => "ساختار فایل بک‌آپ نامعتبر است: جدول «{$problem->table}» ستون «{$problem->column}» را ندارد.",
                BackupProblem::KIND_MISSING_COLUMN => "ساختار فایل بک‌آپ نامعتبر است: جدول «{$problem->table}» در فایل ستون «{$problem->column}» ندارد.",
                BackupProblem::KIND_DUPLICATE_ROW => "ساختار فایل بک‌آپ نامعتبر است: جدول «{$problem->table}» شناسهٔ «{$problem->value}» بیش از یک بار در فایل آمده است.",
                BackupProblem::KIND_MISSING_TABLE => "جدول «{$problem->relatedTable}» در فایل بک‌آپ نیست؛ ابتدا آن را بازگردانی کنید (جدول «{$problem->table}» به آن ارجاع دارد).",
                BackupProblem::KIND_MISSING_REFERENCE => "جدول «{$problem->table}» ردیف «{$problem->rowId}» در ستون «{$problem->column}» به مقدار «{$problem->value}» اشاره می‌کند که در جدول «{$problem->relatedTable}» وجود ندارد؛ ابتدا جدول «{$problem->relatedTable}» را بازگردانی کنید.",
                BackupProblem::KIND_ORPHAN_CHILD => "بازگردانی جدول «{$problem->relatedTable}» ممکن نیست: جدول «{$problem->table}» ردیف «{$problem->rowId}» در ستون «{$problem->column}» به مقدار «{$problem->value}» ارجاع می‌دهد که در فایل نیست؛ ابتدا جدول «{$problem->table}» را بازگردانی یا حذف کنید.",
                default => 'فایل بک‌آپ نامعتبر است.',
            };
        }

        $rest = count($problems) - self::MESSAGE_LIMIT;

        if ($rest > 0) {
            $lines[] = "و {$rest} مورد دیگر.";
        }

        return implode(' | ', $lines);
    }
}
