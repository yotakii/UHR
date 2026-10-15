<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `php artisan db:seed` = القيم الأساسية فقط (آمن للإنتاج والتكرار).
 * بيانات الاختبار منفصلة وصريحة: `php artisan db:seed --class=TestDataSeeder` (تُرفض في الإنتاج).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DefaultsSeeder::class);
    }
}
