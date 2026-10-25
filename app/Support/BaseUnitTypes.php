<?php

namespace App\Support;

/**
 * The one definition of a "base unit" (issue #1245, decision الف).
 *
 * 8  — پایگاه سلامت ضمیمه
 * 9  — خانه بهداشت
 * 10 — پایگاه سلامت غیرضمیمه
 * 18 — خانه بهداشت کارگری
 *
 * 20 (خانه‌های کارگری) is a container/label, not a base house: including it
 * would make a data-driven map unable to show `cases = 0`. The map's unit
 * layer and the population UI (#1244) must both read this constant — never a
 * scattered guess.
 */
final class BaseUnitTypes
{
    /** @var list<int> */
    public const IDS = [8, 9, 10, 18];
}
