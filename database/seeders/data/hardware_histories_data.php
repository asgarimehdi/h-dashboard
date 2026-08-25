<?php

/**
 * Seed data for the hardware audit/history trail (hardware_audits table).
 *
 * KEYED BY n_code (the application-wide standard key that links
 * hardware <-> person <-> user). Each n_code maps to an ordered list of
 * audit entries representing the *subsequent* changes made to that device
 * after it was first created (the initial "created" audit is produced
 * automatically by HardwareAuditObserver when the device is seeded).
 *
 * Each entry:
 *   - action      : one of created|updated|deleted|bulk_mark|bulk_delete|force_deleted|rollback
 *   - source      : one of web|api|import|bulk   (renders the standard Persian label)
 *   - actor_ncode : n_code of the user who performed the action (optional; null -> anonymous)
 *   - days_ago    : how many days before the seed base date the entry occurred
 *   - changes     : array of [{field, old, new}] in the SAME display format the
 *                   observer writes (booleans as 'بله'/'خیر', null/'—' for empty)
 *
 * Timestamps are derived deterministically from a fixed base date so the
 * seeder is idempotent (re-running it will not create duplicate rows).
 */

return [
    // ── AB-17SH-BM2 ──────────────────────────────────────────────
    '9585766959' => [
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4411015056',
            'days_ago' => 295,
            'changes' => [
                ['field' => 'comments', 'old' => '—', 'new' => 'بررسی اولیه شبکه انجام شد'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'api',
            'actor_ncode' => '4400176134',
            'days_ago' => 250,
            'changes' => [
                ['field' => 'mark', 'old' => 'خیر', 'new' => 'بله'],
            ],
        ],
        [
            'action' => 'rollback',
            'source' => 'web',
            'actor_ncode' => '4411015056',
            'days_ago' => 240,
            'changes' => [
                ['field' => 'mark', 'old' => 'بله', 'new' => 'خیر'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'import',
            'actor_ncode' => null,
            'days_ago' => 120,
            'changes' => [
                ['field' => 'ip_local', 'old' => '192.168.175.165', 'new' => '192.168.175.170'],
                ['field' => 'ip_valid', 'old' => '192.168.175.165', 'new' => '192.168.175.170'],
            ],
        ],
    ],

    // ── MA-17SH-DENT ─────────────────────────────────────────────
    '2795950689' => [
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4400176143',
            'days_ago' => 280,
            'changes' => [
                ['field' => 'os', 'old' => '7 Windows', 'new' => '10 Windows'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'bulk',
            'actor_ncode' => '4411015056',
            'days_ago' => 90,
            'changes' => [
                ['field' => 'mark', 'old' => 'خیر', 'new' => 'بله'],
                ['field' => 'comments', 'old' => '—', 'new' => 'تغییر وضعیت در عملیات گروهی'],
            ],
        ],
    ],

    // ── AB-17SH-DR1 ──────────────────────────────────────────────
    '5222452874' => [
        [
            'action' => 'updated',
            'source' => 'api',
            'actor_ncode' => '4400176134',
            'days_ago' => 270,
            'changes' => [
                ['field' => 'shutdown', 'old' => 'خیر', 'new' => 'بله'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4411015056',
            'days_ago' => 60,
            'changes' => [
                ['field' => 'shutdown', 'old' => 'بله', 'new' => 'خیر'],
                ['field' => 'clean_at', 'old' => '—', 'new' => '1403/05/12'],
            ],
        ],
    ],

    // ── AB-17SH-EZDEVAJ ─────────────────────────────────────────
    '7724412612' => [
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4400176143',
            'days_ago' => 260,
            'changes' => [
                ['field' => 'comments', 'old' => '—', 'new' => 'تست اتصال شبکه بی‌سیم'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'import',
            'actor_ncode' => null,
            'days_ago' => 40,
            'changes' => [
                ['field' => 'vlan', 'old' => '1752', 'new' => '1755'],
            ],
        ],
    ],

    // ── AB-17SH-LAB1 ─────────────────────────────────────────────
    '2954704745' => [
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4411015056',
            'days_ago' => 300,
            'changes' => [
                ['field' => 'ram', 'old' => '4096', 'new' => '8192'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'api',
            'actor_ncode' => '4400176134',
            'days_ago' => 200,
            'changes' => [
                ['field' => 'hdd', 'old' => 'HDD', 'new' => 'SSD'],
            ],
        ],
        [
            'action' => 'rollback',
            'source' => 'web',
            'actor_ncode' => '4411015056',
            'days_ago' => 190,
            'changes' => [
                ['field' => 'hdd', 'old' => 'SSD', 'new' => 'HDD'],
            ],
        ],
    ],

    // ── AB-17SH-LAB2 ─────────────────────────────────────────────
    '1600286198' => [
        [
            'action' => 'updated',
            'source' => 'bulk',
            'actor_ncode' => '4411015056',
            'days_ago' => 150,
            'changes' => [
                ['field' => 'mark', 'old' => 'خیر', 'new' => 'بله'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4400176143',
            'days_ago' => 30,
            'changes' => [
                ['field' => 'comments', 'old' => '—', 'new' => 'ارتقای سیستم عامل به ویندوز ۱۱'],
                ['field' => 'os', 'old' => '10 Windows', 'new' => '11 Windows'],
            ],
        ],
    ],

    // ── MA-17SH-LAB3 ─────────────────────────────────────────────
    '9137317062' => [
        [
            'action' => 'updated',
            'source' => 'api',
            'actor_ncode' => '4400176134',
            'days_ago' => 230,
            'changes' => [
                ['field' => 'switch', 'old' => '—', 'new' => 'SW-CORE-01'],
                ['field' => 'port', 'old' => '—', 'new' => 'Gi1/0/12'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4411015056',
            'days_ago' => 75,
            'changes' => [
                ['field' => 'mark', 'old' => 'خیر', 'new' => 'بله'],
            ],
        ],
    ],

    // ── MA-17SH-LAB4 ─────────────────────────────────────────────
    '0885727024' => [
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4400176143',
            'days_ago' => 210,
            'changes' => [
                ['field' => 'cpu', 'old' => 'Intel(R) Core(TM) i3-6100 CPU @ 3.70GHz', 'new' => 'Intel(R) Core(TM) i5-9400 CPU @ 2.90GHz'],
            ],
        ],
    ],

    // ── AB-17SH-P1-3 ─────────────────────────────────────────────
    '7016684242' => [
        [
            'action' => 'updated',
            'source' => 'import',
            'actor_ncode' => null,
            'days_ago' => 180,
            'changes' => [
                ['field' => 'net_type', 'old' => 'wireless', 'new' => 'wired'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4411015056',
            'days_ago' => 50,
            'changes' => [
                ['field' => 'comments', 'old' => '—', 'new' => 'انتقال به شبکه کابلی'],
            ],
        ],
    ],

    // ── AB-17SH-P3-1 ─────────────────────────────────────────────
    '2426240446' => [
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4400176134',
            'days_ago' => 170,
            'changes' => [
                ['field' => 'mark', 'old' => 'خیر', 'new' => 'بله'],
            ],
        ],
        [
            'action' => 'rollback',
            'source' => 'api',
            'actor_ncode' => '4400176134',
            'days_ago' => 160,
            'changes' => [
                ['field' => 'mark', 'old' => 'بله', 'new' => 'خیر'],
            ],
        ],
    ],

    // ── AB-17SH-P3-2 ─────────────────────────────────────────────
    '1081149811' => [
        [
            'action' => 'updated',
            'source' => 'bulk',
            'actor_ncode' => '4411015056',
            'days_ago' => 100,
            'changes' => [
                ['field' => 'mark', 'old' => 'خیر', 'new' => 'بله'],
            ],
        ],
    ],

    // ── AB-17SH-P3-3 ─────────────────────────────────────────────
    '8127028027' => [
        [
            'action' => 'updated',
            'source' => 'web',
            'actor_ncode' => '4400176143',
            'days_ago' => 140,
            'changes' => [
                ['field' => 'motherboard', 'old' => 'H110M-C', 'new' => 'B365M-D3H'],
            ],
        ],
        [
            'action' => 'updated',
            'source' => 'api',
            'actor_ncode' => '4400176134',
            'days_ago' => 20,
            'changes' => [
                ['field' => 'comments', 'old' => '—', 'new' => 'تعویض مادربرد'],
            ],
        ],
    ],
];
