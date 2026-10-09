#!/usr/bin/env bash
# Coverage reporting entrypoint (issue #865).
# Usage: bash scripts/coverage-report.sh [--clover coverage.xml]
#
# Non-blocking by design: this script REPORTS, it never gates. It always
# exits 0 unless its own inputs are missing (wrong invocation), so the
# `coverage-report` CI job can never turn red on a measurement change.
#
# What it reports:
#   1. The app/ denominator number (lines covered / valid, from the Clover
#      file CI's `test` job already produces) — the baseline every later
#      decision depends on.
#   2. The Blade component executable-line count (static measure of the logic
#      that lives outside the coverage denominator), with the method the
#      #865 audit used: `return new class extends Component` range per file,
#      blank and comment-only lines stripped.
#   3. The attribution verdict: whether compiled-Blade coverage can be mapped
#      back to source with stock pcov/PHPUnit. (Verified 2026-10-08: NO —
#      Livewire test mode compiles views per-worker under hashed
#      storage/framework/views/test_N/ paths, so `--coverage-filter` on the
#      compiled dir reports 0.0% per file and inflates the denominator with
#      dead copies. See issue #865's expert review.)
#
# The hard `--min=80` gate is deliberately untouched: changing it before a
# real total exists is out of scope (locked decision in #865's git review).
set -uo pipefail
cd "$(dirname "$0")/.."

CLOVER="coverage.xml"
for arg in "$@"; do
    case "$arg" in
        --clover=*) CLOVER="${arg#--clover=}" ;;
        --clover) shift; CLOVER="${1:-coverage.xml}" ;;
    esac
done

echo "=== Coverage report (issue #865 — informational, non-blocking) ==="
echo ""

# 1. app/ denominator from the Clover report.
if [ -f "$CLOVER" ]; then
    METRICS=$(grep -o '<metrics files="[0-9]*"[^/]*/>' "$CLOVER" | head -1)
    if [ -n "$METRICS" ]; then
        STATEMENTS=$(echo "$METRICS" | grep -o 'statements="[0-9]*"' | head -1 | grep -o '[0-9]*')
        COVERED=$(echo "$METRICS" | grep -o 'coveredstatements="[0-9]*"' | head -1 | grep -o '[0-9]*')
        if [ -n "$STATEMENTS" ] && [ "$STATEMENTS" -gt 0 ] 2>/dev/null; then
            PCT=$(php -r "printf('%.2f', 100 * $COVERED / $STATEMENTS);")
            echo "app/ coverage: ${PCT}% (${COVERED}/${STATEMENTS} statements)"
        else
            echo "app/ coverage: could not parse statement counts from $CLOVER"
        fi
    else
        echo "app/ coverage: no project-level <metrics> in $CLOVER"
    fi
else
    echo "app/ coverage: no clover file at $CLOVER (run the suite with --coverage-clover first)"
fi
echo ""

# 2. Blade component executable-line count (static, same method as #865).
BLADE_TOTAL=0
BLADE_FILES=0
for f in $(grep -rl "return new class extends Component" resources/views/livewire/ 2>/dev/null); do
    COUNT=$(php -r '
        $src = file_get_contents($argv[1]);
        $start = strpos($src, "return new class extends Component");
        if ($start === false) { echo 0; exit; }
        $end = strpos($src, "};", $start);
        if ($end === false) { echo 0; exit; }
        $range = substr($src, $start, $end - $start);
        $n = 0;
        foreach (explode("\n", $range) as $line) {
            $t = trim($line);
            if ($t === "") continue;
            if (str_starts_with($t, "//") || str_starts_with($t, "*") || str_starts_with($t, "/*") || str_starts_with($t, "#")) continue;
            $n++;
        }
        echo $n;
    ' "$f")
    BLADE_TOTAL=$((BLADE_TOTAL + COUNT))
    BLADE_FILES=$((BLADE_FILES + 1))
done
echo "Blade component logic (outside coverage denominator): ${BLADE_TOTAL} executable lines across ${BLADE_FILES} files"
echo ""

# 3. Attribution verdict (verified, not re-derived per run).
echo "Blade attribution: NOT mappable with stock pcov/PHPUnit —"
echo "  Livewire test mode compiles views per-worker to hashed"
echo "  storage/framework/views/test_N/ paths; --coverage-filter on the"
echo "  compiled dir yields 0.0% per file (verified 2026-10-08)."
echo "  Component behaviour IS executed by Livewire::test(...) suites;"
echo "  only the measurement is blind."
echo ""
echo "Gate status: --min=80 on app/ unchanged (hard gate out of scope until a real total exists)."
echo "=== end coverage report ==="
exit 0
