<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Issue #1484 — pin the CI job inventory to `.github/workflows/test.yml`.
 *
 * `docs/agents/testing.md` owns the canonical table and both `yq` derivations;
 * `AGENTS.md` repeats the two sets and the total count. Nothing derived either
 * from the workflow, and the inventory rotted once already (#1479).
 *
 * Hand-parsed: no `symfony/yaml` (undeclared transitively, and the `yaml`
 * extension is absent from every `setup-php` block). Lines are split with
 * `explode("\n")`, never bare `/\R/`: the UTF-8 checkmark in `testing.md:51`
 * makes byte-wise PCRE read `0x85` as NEL and shift every later line by one.
 * Columns are resolved by header name, so an added `Timeout` column is
 * harmless. Display names, timeouts and triggers stay unpinned: those are
 * per-job config, not contract (the `e2e` timeout already moved 35 → 45).
 */
final class DocsCiJobsGuardTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/test.yml';

    private const CANONICAL = 'docs/agents/testing.md';

    private const SUMMARY = 'AGENTS.md';

    private const REFERENCE = 'references/api-endpoints.md';

    /**
     * Raw Gate cells keyed by job key, captured by canonicalRows() so the
     * per-row assertion can quote the cell a reader actually sees.
     *
     * @var array<string, string>
     */
    private array $canonicalGateCells = [];

    /**
     * A0 — the workflow yields job keys and a classifiable gate for each.
     *
     * The parser fails on an unkeyed 2-space line (a silently dropped job) and
     * on an expression-valued `continue-on-error` (which a naive `=== 'true'`
     * check would call blocking). This pins the shape the rest relies on.
     */
    public function test_workflow_parses_into_job_keys_and_gates(): void
    {
        $workflow = $this->workflowJobs();

        $this->assertGreaterThanOrEqual(
            1,
            count($workflow['keys']),
            '.github/workflows/test.yml parsed zero jobs — the `jobs:` block was reindented or reshaped; update the parser.'
        );
        $this->assertSame(
            $workflow['keys'],
            array_keys($workflow['gates']),
            '.github/workflows/test.yml parsed a job key without a gate entry; the parser is inconsistent.'
        );

        foreach ($workflow['gates'] as $job => $gate) {
            $this->assertContains(
                $gate,
                ['true', 'false', 'absent'],
                sprintf(
                    'test.yml: job `%s` has continue-on-error value `%s` — cannot classify this job\'s gate; the guard needs updating.',
                    $job,
                    $gate
                )
            );
        }
    }

    /**
     * A1 — the canonical table and the workflow declare the same job keys.
     */
    public function test_canonical_table_keys_equal_the_workflow_keys(): void
    {
        $this->assertSameSets(
            $this->workflowJobs()['keys'],
            $this->canonicalRows()['keys'],
            'Canonical job keys in docs/agents/testing.md vs .github/workflows/test.yml'
        );
    }

    /**
     * A2 — the blocking sets match; `absent` and `false` both mean blocking,
     * only an explicit `continue-on-error: true` is informational.
     */
    public function test_canonical_blocking_set_equals_the_workflow_blocking_set(): void
    {
        $workflow = $this->workflowJobs();
        $canonical = $this->canonicalRows();

        $workflowBlocking = array_keys(array_filter(
            $workflow['gates'],
            static fn (string $gate): bool => $gate !== 'true'
        ));
        $canonicalBlocking = array_keys(array_filter(
            $canonical['gates'],
            static fn (string $gate): bool => $gate === 'blocking'
        ));

        $this->assertSameSets(
            $workflowBlocking,
            $canonicalBlocking,
            'Canonical blocking set vs .github/workflows/test.yml (only an explicit `continue-on-error: true` is informational)'
        );
    }

    /**
     * A3 — every Gate cell agrees with its own job's `continue-on-error`.
     *
     * A cell naming both markers (or neither) is ambiguous and fails in the
     * parser rather than being guessed into a set.
     */
    public function test_each_canonical_gate_cell_agrees_with_its_job(): void
    {
        $workflow = $this->workflowJobs();
        $canonical = $this->canonicalRows();
        $mismatches = [];

        foreach ($canonical['gates'] as $job => $tableGate) {
            if (! array_key_exists($job, $workflow['gates'])) {
                $mismatches[] = sprintf('`%s`: listed in testing.md but absent from test.yml', $job);

                continue;
            }

            $workflowValue = $workflow['gates'][$job];
            $workflowGate = $workflowValue === 'true' ? 'informational' : 'blocking';

            if ($tableGate !== $workflowGate) {
                $mismatches[] = sprintf(
                    '`%s`: testing.md Gate cell "%s" says %s, but test.yml has continue-on-error: %s (=> %s)',
                    $job,
                    $this->canonicalGateCells[$job] ?? '(unknown)',
                    $tableGate,
                    $workflowValue === 'absent' ? '(absent)' : $workflowValue,
                    $workflowGate
                );
            }
        }

        $this->assertSame(
            [],
            $mismatches,
            'Canonical Gate cells disagree with .github/workflows/test.yml: '
                .implode('; ', $mismatches)
                .' — update the row to match the workflow, not the other way round.'
        );
    }

    /**
     * A4 — the `AGENTS.md` summary lists the same two sets and the right total.
     *
     * The sentence is matched as a whole, exactly once, with the total word
     * ("twelve") pinned too: "eight jobs" survived #1479 as an independent
     * count beside two independent lists. Whitespace is collapsed first, so a
     * future reflow is harmless.
     */
    public function test_agents_md_summary_lists_the_same_two_sets(): void
    {
        $collapsed = (string) preg_replace(
            '/\s+/',
            ' ',
            (string) file_get_contents(base_path(self::SUMMARY))
        );

        $pattern = '/\*\*([A-Za-z]+)\*\*\s+jobs\b.{0,160}?\b([A-Za-z]+)\s+blocking\s+\(([^)]*)\)\s+and\s+([A-Za-z]+)\s+`continue-on-error`\s+\(([^)]*)\)/i';
        $matchCount = preg_match_all($pattern, $collapsed, $matches, PREG_SET_ORDER);

        $this->assertSame(
            1,
            $matchCount,
            'AGENTS.md must carry exactly one "N jobs — N blocking (...) and N `continue-on-error` (...)" summary; found '
                .$matchCount
                .'. Two means a second summary appeared; zero means the sentence was reworded — re-point the guard.'
        );

        $match = $matches[0];
        $workflow = $this->workflowJobs();
        $blocking = array_keys(array_filter(
            $workflow['gates'],
            static fn (string $gate): bool => $gate !== 'true'
        ));
        $informational = array_keys(array_filter(
            $workflow['gates'],
            static fn (string $gate): bool => $gate === 'true'
        ));

        $this->assertNumberWord(count($workflow['keys']), $match[1], 'AGENTS.md total ("... jobs")');
        $this->assertNumberWord(count($blocking), $match[2], 'AGENTS.md blocking count');
        $this->assertNumberWord(count($informational), $match[4], 'AGENTS.md informational count');

        $this->assertSameSets($blocking, $this->splitCommaList($match[3]), 'AGENTS.md blocking list vs .github/workflows/test.yml');
        $this->assertSameSets($informational, $this->splitCommaList($match[5]), 'AGENTS.md `continue-on-error` list vs .github/workflows/test.yml');
    }

    /**
     * A5 — the canonical table is the single home.
     *
     * Three checks: one `Job key` table repo-wide, no inventory or `yq .jobs`
     * copy under `references/`, and the stale eight-key sequence gone from
     * `docs/` and `AGENTS.md`. Check 2 is deliberately narrow:
     * `docs/agents/e2e.md:67` legitimately names seven job keys in one
     * sentence, so a repo-wide enumeration scan would be a false positive.
     */
    public function test_no_second_job_inventory_outside_the_canonical_table(): void
    {
        $headers = [];

        foreach ($this->markdownFiles() as $relativePath => $absolutePath) {
            foreach ($this->linesOf($absolutePath) as $number => $line) {
                if (preg_match('/^\|\s*Job key\s*\|/i', $line) === 1) {
                    $headers[] = $relativePath.':'.$number;
                }
            }
        }

        $this->assertCount(
            1,
            $headers,
            'Expected exactly one "| Job key |" table header in markdown, found: '
                .implode(', ', $headers)
                .'. The canonical table is the single home (#1479) — do not copy it.'
        );
        $this->assertStringStartsWith(
            self::CANONICAL.':',
            $headers[0],
            'The only "| Job key |" table header must live in docs/agents/testing.md; found at '
                .$headers[0]
                .'. The canonical table is the single home (#1479).'
        );

        $keySet = array_fill_keys($this->workflowJobs()['keys'], true);
        $violations = [];

        foreach ($this->markdownFilesIn(dirname(self::REFERENCE)) as $relativePath => $absolutePath) {
            foreach ($this->linesOf($absolutePath) as $number => $line) {
                if (preg_match('/yq\s+.*\.jobs/', $line) === 1) {
                    $violations[] = $relativePath.':'.$number.' copies the `yq .jobs` derivation';
                }

                preg_match_all('/`([^`]+)`/', $line, $tokens);
                $named = [];
                foreach ($tokens[1] as $token) {
                    if (isset($keySet[trim($token)])) {
                        $named[trim($token)] = true;
                    }
                }

                if (count($named) >= 3) {
                    $violations[] = $relativePath.':'.$number.' lists job keys inline ('
                        .implode(', ', array_keys($named))
                        .')';
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'A second CI job inventory appeared under references/: '
                .implode('; ', $violations)
                .'. The canonical table is the single home (#1479).'
        );

        $staleSequence = '`lint`/`test`/`coverage-report`/`typecheck`/`e2e`/`mutation`/`phpstan`/`test-php86`';
        $staleHits = [];
        $targets = $this->markdownFilesIn('docs');
        $targets[self::SUMMARY] = base_path(self::SUMMARY);

        foreach ($targets as $relativePath => $absolutePath) {
            foreach ($this->linesOf($absolutePath) as $number => $line) {
                if (str_contains($line, $staleSequence)) {
                    $staleHits[] = $relativePath.':'.$number;
                }
            }
        }

        $this->assertSame(
            [],
            $staleHits,
            'The stale eight-key job enumeration reappeared at '
                .implode(', ', $staleHits)
                .'. The canonical table is the single home (#1479) — point at it instead.'
        );
    }

    /**
     * A6 — the canonical heading's three number words equal the derived counts.
     */
    public function test_canonical_heading_counts_match_the_derived_sets(): void
    {
        $heading = null;
        $headingNumber = null;

        foreach ($this->linesOf(base_path(self::CANONICAL)) as $number => $line) {
            if (preg_match('/^## CI jobs\b/', $line) === 1) {
                $heading = $line;
                $headingNumber = $number;

                break;
            }
        }

        $this->assertNotNull(
            $heading,
            'docs/agents/testing.md has no "## CI jobs" heading — re-point the guard at the canonical section.'
        );

        $matched = preg_match(
            '/\b([A-Za-z]+) jobs,\s+([A-Za-z]+) blocking,\s+([A-Za-z]+) informational\b/i',
            (string) $heading,
            $matches
        );

        $this->assertSame(
            1,
            $matched,
            'testing.md:'.$headingNumber.' heading must read "<total> jobs, <blocking> blocking, <informational> informational" — update the guard.'
        );

        $workflow = $this->workflowJobs();
        $blockingCount = count(array_filter(
            $workflow['gates'],
            static fn (string $gate): bool => $gate !== 'true'
        ));
        $expected = [count($workflow['keys']), $blockingCount, count($workflow['keys']) - $blockingCount];

        foreach ([1, 2, 3] as $index) {
            $this->assertNumberWord(
                $expected[$index - 1],
                $matches[$index],
                'testing.md:'.$headingNumber.' heading'
            );
        }
    }

    /**
     * A7 — both `yq` output comments equal the derived sets.
     */
    public function test_derivation_outputs_match_the_workflow(): void
    {
        $derivations = [];

        foreach ($this->linesOf(base_path(self::CANONICAL)) as $number => $line) {
            if (str_contains($line, 'yq -r')) {
                $derivations[$number] = $line;
            }
        }

        $this->assertCount(
            2,
            $derivations,
            'docs/agents/testing.md must carry exactly two `yq -r` derivations (all keys, blocking set); found '
                .count($derivations)
                .'.'
        );

        $workflow = $this->workflowJobs();
        $blocking = array_keys(array_filter(
            $workflow['gates'],
            static fn (string $gate): bool => $gate !== 'true'
        ));

        foreach ($derivations as $number => $line) {
            $hashPosition = strpos($line, '#');

            $this->assertNotFalse(
                $hashPosition,
                'testing.md:'.$number.' lost the pasted `yq` output comment — re-run the command and paste its output.'
            );

            if (str_contains($line, '| keys |')) {
                $want = $workflow['keys'];
                $label = 'all job keys';
            } elseif (str_contains($line, 'continue-on-error')) {
                $want = $blocking;
                $label = 'the blocking set';
            } else {
                $this->fail('testing.md:'.$number.' is an unrecognised `yq` derivation — update the guard.');
            }

            $this->assertSameSets(
                $want,
                $this->splitCommaList(substr($line, (int) $hashPosition + 1)),
                'testing.md:'.$number.' `yq` output ('.$label.') vs .github/workflows/test.yml'
            );
        }
    }

    /**
     * Parse `.github/workflows/test.yml` into job keys and raw gates.
     *
     * `jobs:` is the last top-level key, so the slice runs to EOF (it also
     * stops at the next non-indented, non-comment line if one is ever added).
     * Job keys are the only 2-space key-shaped lines in the slice; any other is
     * reported by line so a reindent cannot silently shrink the set.
     *
     * @return array{keys: list<string>, gates: array<string, string>}
     */
    private function workflowJobs(): array
    {
        $lines = $this->linesOf(base_path(self::WORKFLOW));
        $jobsStart = null;

        foreach ($lines as $number => $line) {
            if (preg_match('/^jobs:[ \t]*$/', $line) === 1) {
                $jobsStart = $number;

                break;
            }
        }

        if ($jobsStart === null) {
            $this->fail('.github/workflows/test.yml has no top-level `jobs:` block — the guard cannot find the job set.');
        }

        $jobKeyPattern = '/^ {2}([A-Za-z_][A-Za-z0-9_-]*):[ \t]*$/';
        $keyShapedPattern = '/^ {2}[^ #].*:[ \t]*$/';
        $keys = [];
        $gates = [];
        $currentJob = null;

        foreach ($lines as $number => $line) {
            if ($number <= $jobsStart) {
                continue;
            }

            if (preg_match('/^[^ \t#]/', $line) === 1) {
                break;
            }

            if (preg_match($keyShapedPattern, $line) === 1) {
                if (preg_match($jobKeyPattern, $line, $matches) !== 1) {
                    $this->fail(sprintf(
                        'test.yml:%d is a 2-space key-shaped line that is not a GitHub job id: "%s" — the workflow shape changed; update the parser.',
                        $number,
                        trim($line)
                    ));
                }

                $currentJob = $matches[1];
                $keys[] = $currentJob;
                $gates[$currentJob] = 'absent';
            }

            if ($currentJob !== null && preg_match('/^ {4}continue-on-error:[ \t]*(\S+)/', $line, $matches) === 1) {
                $gates[$currentJob] = $matches[1];
            }
        }

        if ($keys === []) {
            $this->fail('.github/workflows/test.yml parsed zero jobs — the `jobs:` block was reindented or reshaped; update the parser.');
        }

        foreach ($gates as $job => $gate) {
            if (! in_array($gate, ['true', 'false', 'absent'], true)) {
                $this->fail(sprintf(
                    'test.yml: job `%s` has continue-on-error: %s — cannot classify this job\'s gate; the guard needs updating.',
                    $job,
                    $gate
                ));
            }
        }

        return ['keys' => $keys, 'gates' => $gates];
    }

    /**
     * Parse the canonical table by header, never by column position.
     *
     * The Gate cell is classified only after computing both markers: both or
     * neither is ambiguous and fails. `non-blocking` is stripped before
     * looking for the `blocking` token so a parenthetical cannot misclassify.
     *
     * @return array{keys: list<string>, gates: array<string, string>}
     */
    private function canonicalRows(): array
    {
        $this->canonicalGateCells = [];
        $lines = $this->linesOf(base_path(self::CANONICAL));
        $headerNumber = null;
        $header = null;

        foreach ($lines as $number => $line) {
            if (preg_match('/^\|\s*Job key\s*\|/i', $line) === 1) {
                $headerNumber = $number;
                $header = $this->tableCells($line);

                break;
            }
        }

        if ($headerNumber === null || $header === null) {
            $this->fail('docs/agents/testing.md has no "| Job key |" table header — the canonical CI jobs table is missing.');
        }

        $keyIndex = array_search('Job key', $header, true);
        $gateIndex = array_search('Gate', $header, true);

        if ($keyIndex === false || $gateIndex === false) {
            $this->fail(
                'The canonical CI jobs table header must carry "Job key" and "Gate" columns; found: '
                    .implode(' | ', $header)
                    .' — update the guard.'
            );
        }

        $keys = [];
        $gates = [];

        foreach ($lines as $number => $line) {
            if ($number <= $headerNumber + 1) {
                continue;
            }

            $trimmed = trim($line);

            if ($trimmed === '' || ! str_starts_with($trimmed, '|')) {
                break;
            }

            $cells = $this->tableCells($line);

            if (! array_key_exists($keyIndex, $cells) || ! array_key_exists($gateIndex, $cells)) {
                $this->fail(sprintf('testing.md:%d is a malformed canonical table row: %s', $number, $trimmed));
            }

            $key = $cells[$keyIndex];
            $rawGate = $cells[$gateIndex];

            if ($key === '') {
                $this->fail(sprintf('testing.md:%d is a canonical table row with an empty job key.', $number));
            }

            $keys[] = $key;
            $gates[$key] = $this->classifyGateCell($number, $key, $rawGate);
            $this->canonicalGateCells[$key] = $rawGate;
        }

        if ($keys === []) {
            $this->fail('The canonical CI jobs table in docs/agents/testing.md has no data rows; the guard would pass vacuously.');
        }

        return ['keys' => $keys, 'gates' => $gates];
    }

    /**
     * Classify a Gate cell: both markers or neither is a failure, never a guess.
     */
    private function classifyGateCell(int $lineNumber, string $job, string $rawCell): string
    {
        $normalized = strtolower(trim((string) preg_replace(
            '/\s+/',
            ' ',
            str_replace(['`', '*'], '', $rawCell)
        )));

        $hasContinueOnError = str_contains($normalized, 'continue-on-error');
        $hasBlocking = preg_match('/\bblocking\b/', str_replace('non-blocking', '', $normalized)) === 1;

        if ($hasContinueOnError === $hasBlocking) {
            $this->fail(sprintf(
                'testing.md:%d Gate cell for `%s` — "%s" — is ambiguous: it names %s. The canonical table must say exactly one of "blocking" or "continue-on-error".',
                $lineNumber,
                $job,
                $rawCell,
                $hasContinueOnError ? 'both markers' : 'neither marker'
            ));
        }

        return $hasContinueOnError ? 'informational' : 'blocking';
    }

    /**
     * Split a markdown table row into trimmed cells with markup stripped.
     *
     * @return list<string>
     */
    private function tableCells(string $line): array
    {
        return array_map(
            static fn (string $cell): string => trim(str_replace(['`', '*'], '', $cell)),
            explode('|', trim(trim($line), '|'))
        );
    }

    /**
     * Read a file as 1-based line number => content, CR stripped.
     *
     * Never `preg_split('/\R/')`: see the class docblock.
     *
     * @return array<int, string>
     */
    private function linesOf(string $absolutePath): array
    {
        $lines = [];

        foreach (explode("\n", (string) file_get_contents($absolutePath)) as $index => $line) {
            $lines[$index + 1] = rtrim($line, "\r");
        }

        return $lines;
    }

    /**
     * Every markdown file the single-home rule covers: root, docs/, references/.
     *
     * @return array<string, string> relative path => absolute path
     */
    private function markdownFiles(): array
    {
        $files = [];

        foreach (File::glob(base_path('*.md')) ?: [] as $absolutePath) {
            $files[basename($absolutePath)] = $absolutePath;
        }

        return $files + $this->markdownFilesIn('docs') + $this->markdownFilesIn('references');
    }

    /**
     * Recursively collect markdown files under a repo-relative directory.
     *
     * @return array<string, string> relative path => absolute path
     */
    private function markdownFilesIn(string $relativeDirectory): array
    {
        $absoluteDirectory = base_path($relativeDirectory);

        if (! is_dir($absoluteDirectory)) {
            return [];
        }

        $files = [];

        foreach (File::allFiles($absoluteDirectory) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $files[$relativeDirectory.'/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getPathname();
        }

        return $files;
    }

    /**
     * Split a comma-separated list, dropping blanks and backticks.
     *
     * @return list<string>
     */
    private function splitCommaList(string $list): array
    {
        $items = [];

        foreach (explode(',', $list) as $item) {
            $item = trim(str_replace('`', '', $item));

            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Assert two string sets are equal, reporting each direction separately.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $actual
     */
    private function assertSameSets(array $expected, array $actual, string $context): void
    {
        $expected = $this->sorted($expected);
        $actual = $this->sorted($actual);

        $missing = array_values(array_diff($expected, $actual));
        $extra = array_values(array_diff($actual, $expected));

        $this->assertSame([], $missing, $context.' — missing from the document: '.implode(', ', $missing).'.');
        $this->assertSame([], $extra, $context.' — stale/extra in the document: '.implode(', ', $extra).'.');
        $this->assertSame($expected, $actual, $context.' — the two sets differ.');
    }

    /**
     * Sort without deduplicating: a repeated key is a defect the counts must see.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return array_values($values);
    }

    /**
     * Assert a spelled-out count word equals the derived count.
     */
    private function assertNumberWord(int $expected, string $word, string $context): void
    {
        /** @var array<string, int> $map */
        $map = [
            'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4,
            'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
            'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13,
            'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16,
            'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20,
        ];

        $key = strtolower(trim($word));

        if (! array_key_exists($key, $map)) {
            $this->fail(sprintf(
                '%s uses the unrecognised number word "%s" — add it to the guard\'s word→int map.',
                $context,
                $word
            ));
        }

        $this->assertSame(
            $expected,
            $map[$key],
            sprintf(
                '%s says "%s" (%d) but the derived count is %d — update the doc from the yq output, never by hand.',
                $context,
                $word,
                $map[$key],
                $expected
            )
        );
    }
}
