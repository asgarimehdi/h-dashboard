#!/usr/bin/env bash
# sync-beta.sh — گزارش وضعیت برنچ فعلی نسبت به origin/beta و sync امن.
#
# چه می‌کند: گزارش `behind X, ahead Y` + وضعیت ancestor، و اگر برنچ فقط
# عقب باشد با `git merge --ff-only` تا origin/beta جلو می‌برد (fast-forward).
#
# چه نمی‌کند: هرگز merge commit نمی‌سازد، rebase نمی‌کند، push نمی‌کند،
# برنچ را عوض نمی‌کند و روی برنچِ واگرا هیچ تغییری نمی‌دهد (فقط گزارش + exit 1).
#
# مقایسه همیشه با رفرنسِ صریح origin/beta است، نه upstream کانفیگ‌شده —
# چون `git status` با upstream اشتباه گمراه‌کننده است (ایشوی #744).
set -euo pipefail

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "error: داخل work tree گیت نیستید." >&2
    exit 1
fi

if ! git rev-parse --verify --quiet origin/beta >/dev/null; then
    echo "error: رفرنس origin/beta پیدا نشد — اول remote و فریفچ آن را بسازید." >&2
    exit 1
fi

git fetch origin beta

ahead=$(git rev-list --left-right --count HEAD...origin/beta | cut -f1)
behind=$(git rev-list --left-right --count HEAD...origin/beta | cut -f2)

if git merge-base --is-ancestor origin/beta HEAD; then
    echo "behind ${behind}, ahead ${ahead} — برنچ از origin/beta جلوتر یا هم‌تراز است (ancestor: بله)."
    echo "همگام است؛ کاری لازم نیست."
    exit 0
fi

echo "behind ${behind}, ahead ${ahead} — برنچ از origin/beta عقب است (ancestor: خیر)."

if [ "${ahead}" -gt 0 ]; then
    echo "خطا: برنچ واگرا شده (ahead ${ahead}, behind ${behind}) — sync نمی‌کنم." >&2
    echo "به‌صورت دستی resolve کنید؛ این اسکریپت هرگز merge خودکار نمی‌سازد." >&2
    exit 1
fi

if git merge --ff-only origin/beta >/dev/null; then
    echo "fast-forward انجام شد: $(git rev-parse --short HEAD) ← origin/beta"
else
    echo "خطا: fast-forward ممکن نشد — تغییری داده نشد." >&2
    exit 1
fi
