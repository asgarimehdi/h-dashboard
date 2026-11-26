<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UsersTableSeeder extends Seeder
{
    /**
     * The demo accounts this seeder creates, in insertion order.
     *
     * RoleSeeder grants them `admin` by this identity, never by bare id (#1847).
     */
    public const DEMO_N_CODES = ['4411015056', '4400176134', '4400176143'];

    /**
     * The three accounts seeded here all share one trivial literal password,
     * so this seeder has no business running in production (#1036).
     *
     * DatabaseSeeder asks this before calling the seeder, so the refusal in
     * run() cannot abort the reference-data batch of a production
     * `php artisan migrate --seed`. The decision intentionally lives here and
     * not at the call site.
     */
    public static function isAllowed(): bool
    {
        return ! app()->isProduction();
    }

    public function run()
    {
        if (! self::isAllowed()) {
            throw new RuntimeException(
                'UsersTableSeeder is disabled in production: it seeds demo accounts that share one '
                .'publicly-known password. Provision an administrator out of band instead. '
                .'اجرای UsersTableSeeder در محیط production مسدود است (#1036).'
            );
        }

        DB::table('users')->insert(array_map(fn (string $nCode) => [
            'n_code' => $nCode,
            'password' => Hash::make('12345678'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ], self::DEMO_N_CODES));
    }
}
