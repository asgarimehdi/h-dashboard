#!/usr/bin/env bash
# sync-beta.sh — گزارش وضعیت برنچ فعلی نسبت به upstream/beta و sync امن.
#
# چه می‌کند: گزارش `behind X, ahead Y` + وضعیت ancestor، و اگر برنچ فقط
# عقب باشد با `git merge --ff-only` تا upstream/beta جلو می‌برد (fast-forward).
#
# چه نمی‌کند: هرگز merge commit نمی‌سازد، rebase نمی‌کند، برنچ را عوض
# نمی‌کند و روی برنچِ واگرا هیچ تغییری نمی‌دهد (فقط گزارش + exit 1).
#
# ترتیب ریموت‌ها (چیدمان fork):
#   origin   → فورک ما (Shabakebehdasht) — برنچ متناظر اینجا push می‌شود
#   upstream → ریپوی اصلی (asgarimehdi) — PR ها به beta آن می‌روند
#
# منبع حقیقت، بتای **ریپوی اصلی** است، نه بتای فورک. دلیل: بتای فورک یک آینه
# است و می‌تواند کهنه بماند؛ اگر با آن مقایسه کنیم، وقتی بتای اصلی جلو رفته و
# آینه push نشده، اسکریپت «همگام» گزارش می‌دهد در حالی که عقبیم — همان دروغی
# که با ریموت اشتباه قبلی (haileen5) اتفاق افتاد.
#
# سازگاری با اعضای تیم که هنوز `upstream` ندارند: اگر ریموت `upstream`
# تعریف نشده باشد، اسکریپت به ریموتی که URL آن به ریپوی اصلی اشاره می‌کند
# برمی‌گردد، و در نهایت به رفتار قدیمی (`origin/beta`) می‌افتد. یعنی کسی
# که فقط همین فایل را pull کند و هیچ کاری نکند، همچنان کار می‌کند.
# `--publish` بدون تشخیص ریپوی اصلی، خطا می‌دهد چون معلوم نیست کجا push شود.
#
# push فقط با فلگ صریح `--publish` انجام می‌شود، و آن هم فقط fast-forward:
#   --publish → اول beta فورک را از beta اصلی push می‌کند، بعد برنچ فعلی
#               را تا همان نقطه ff می‌کند. هرگز force push نمی‌کند.
# بدون فلگ، این اسکریپت هیچ push یا هیچ تغییری در ریموت انجام نمی‌دهد.
#
# رفرنس همیشه صریح نوشته می‌شود (نه `@{upstream}`) چون برنچ فعلی ممکن است
# برنچ متناظر خودش را track کند، نه beta را (ایشوی #744).
set -euo pipefail

MAIN_REPO_RE='asgarimehdi/h-dashboard'

publish=0
for arg in "$@"; do
    case "${arg}" in
        --publish) publish=1 ;;
        -h|--help)
            sed -n '2,28p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "error: آرگومان ناشناخته '${arg}' (فقط --publish)." >&2
            exit 2
            ;;
    esac
done

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "error: داخل work tree گیت نیستید." >&2
    exit 1
fi

# --- تشخیص ریموت «ریپوی اصلی» ------------------------------------------------------
# اول `upstream` اگر هست؛ وگرنه هر ریموتی که URL اش به ریپوی اصلی بخورد.
# اگر هیچ‌کدام نبود، main_remote خالی می‌ماند و به رفتار قدیمی برمی‌گردیم.
main_remote=""
if git remote get-url upstream >/dev/null 2>&1; then
    main_remote="upstream"
else
    for r in $(git remote); do
        if git remote get-url "$r" 2>/dev/null | grep -qE "${MAIN_REPO_RE}"; then
            main_remote="$r"
            break
        fi
    done
fi

if [ -n "${main_remote}" ]; then
    main_beta="${main_remote}/beta"
    git fetch "${main_remote}" beta
else
    # ریپوی اصلی شناسایی نشد → رفتار قدیمی (سازگار با کلون‌های قبلی تیم).
    main_beta="origin/beta"
    echo "هشدار: ریموت ریپوی اصلی پیدا نشد — از origin/beta استفاده می‌کنم." >&2
    echo "      برای دیدن همیشه به‌روز، یک‌بار اجرا کنید:" >&2
    echo "        git remote add upstream https://github.com/asgarimehdi/h-dashboard.git" >&2
fi

if ! git rev-parse --verify --quiet "${main_beta}" >/dev/null; then
    echo "error: رفرنس ${main_beta} پیدا نشد — اول remote و فریفچ آن را بسازید." >&2
    exit 1
fi

# --- گام ۱ (اختیاری): آینه کردن beta اصلی روی beta فورک --------------------------
if [ "${publish}" -eq 1 ]; then
    if [ -z "${main_remote}" ]; then
        echo "error: --publish نیاز به تشخیص ریپوی اصلی دارد ولی پیدا نشد." >&2
        echo "       اول ریموت upstream را اضافه کنید تا معلوم باشد beta اصلی از کجا می‌آید." >&2
        exit 1
    fi

    # push مقصد باید فورک باشد، نه خود ریپوی اصلی.
    if [ "${main_remote}" = "origin" ]; then
        echo "error: origin خودِ ریپوی اصلی است — push به آن یعنی نوشتن روی پروژه اصلی." >&2
        exit 1
    fi

    up_sha=$(git rev-parse "${main_beta}")
    if git rev-parse --verify --quiet origin/beta >/dev/null; then
        fork_sha=$(git rev-parse origin/beta)
    else
        fork_sha=""
    fi

    if [ -z "${fork_sha}" ]; then
        echo "beta فورک وجود ندارد — از ${main_beta} می‌سازم."
        git push origin "${up_sha}:refs/heads/beta"
    elif [ "${fork_sha}" = "${up_sha}" ]; then
        echo "بتای فورک با بتای اصلی یکسان است (${up_sha:0:7}) — push لازم نیست."
    elif git merge-base --is-ancestor "${fork_sha}" "${up_sha}"; then
        echo "بتای فورک عقب است (${fork_sha:0:7} → ${up_sha:0:7}) — push می‌کنم."
        git push origin "${up_sha}:refs/heads/beta"
    else
        echo "خطا: بتای فورک (${fork_sha:0:7}) و بتای اصلی (${up_sha:0:7}) واگرا هستند" >&2
        echo "این اسکریپت هرگز force push نمی‌کند؛ دستی resolve کنید." >&2
        exit 1
    fi
fi

# --- گام ۲: همگام‌سازی برنچ فعلی با beta ریپوی اصلی ------------------------------
ahead=$(git rev-list --left-right --count "HEAD...${main_beta}" | cut -f1)
behind=$(git rev-list --left-right --count "HEAD...${main_beta}" | cut -f2)

if git merge-base --is-ancestor "${main_beta}" HEAD; then
    echo "behind ${behind}, ahead ${ahead} — برنچ از ${main_beta} جلوتر یا هم‌تراز است (ancestor: بله)."
    echo "همگام است؛ کاری لازم نیست."
    exit 0
fi

echo "behind ${behind}, ahead ${ahead} — برنچ از ${main_beta} عقب است (ancestor: خیر)."

if [ "${ahead}" -gt 0 ]; then
    echo "خطا: برنچ واگرا شده (ahead ${ahead}, behind ${behind}) — sync نمی‌کنم." >&2
    echo "به‌صورت دستی resolve کنید؛ این اسکریپت هرگز merge خودکار نمی‌سازد." >&2
    exit 1
fi

if git merge --ff-only "${main_beta}" >/dev/null; then
    echo "fast-forward انجام شد: $(git rev-parse --short HEAD) ← ${main_beta}"
else
    echo "خطا: fast-forward ممکن نشد — تغییری داده نشد." >&2
    exit 1
fi
