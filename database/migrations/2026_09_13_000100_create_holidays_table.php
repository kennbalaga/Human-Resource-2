<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->string('type', 20)->default('regular');
            $table->timestamps();
        });

        /*
         * Philippine regular holidays and special non-working days for the two
         * years the system is demonstrated across. Movable feasts follow Easter
         * (5 Apr 2026, 28 Mar 2027) and National Heroes Day the last Monday of
         * August. The two Eids are left out on purpose: they are proclaimed
         * each year once the dates are sighted, and a guessed date would mark
         * the wrong day as a holiday. Check the year's proclamation and add
         * them, and any declared special day, as they are announced.
         */
        $holidays = [
            ['2026-01-01', 'New Year\'s Day', 'regular'],
            ['2026-02-17', 'Chinese New Year', 'special'],
            ['2026-04-02', 'Maundy Thursday', 'regular'],
            ['2026-04-03', 'Good Friday', 'regular'],
            ['2026-04-04', 'Black Saturday', 'special'],
            ['2026-04-09', 'Araw ng Kagitingan', 'regular'],
            ['2026-05-01', 'Labor Day', 'regular'],
            ['2026-06-12', 'Independence Day', 'regular'],
            ['2026-08-21', 'Ninoy Aquino Day', 'special'],
            ['2026-08-31', 'National Heroes Day', 'regular'],
            ['2026-11-01', 'All Saints\' Day', 'special'],
            ['2026-11-02', 'All Souls\' Day', 'special'],
            ['2026-11-30', 'Bonifacio Day', 'regular'],
            ['2026-12-08', 'Feast of the Immaculate Conception', 'special'],
            ['2026-12-24', 'Christmas Eve', 'special'],
            ['2026-12-25', 'Christmas Day', 'regular'],
            ['2026-12-30', 'Rizal Day', 'regular'],
            ['2026-12-31', 'Last Day of the Year', 'special'],
            ['2027-01-01', 'New Year\'s Day', 'regular'],
            ['2027-02-06', 'Chinese New Year', 'special'],
            ['2027-03-25', 'Maundy Thursday', 'regular'],
            ['2027-03-26', 'Good Friday', 'regular'],
            ['2027-03-27', 'Black Saturday', 'special'],
            ['2027-04-09', 'Araw ng Kagitingan', 'regular'],
            ['2027-05-01', 'Labor Day', 'regular'],
            ['2027-06-12', 'Independence Day', 'regular'],
            ['2027-08-21', 'Ninoy Aquino Day', 'special'],
            ['2027-08-30', 'National Heroes Day', 'regular'],
            ['2027-11-01', 'All Saints\' Day', 'special'],
            ['2027-11-02', 'All Souls\' Day', 'special'],
            ['2027-11-30', 'Bonifacio Day', 'regular'],
            ['2027-12-08', 'Feast of the Immaculate Conception', 'special'],
            ['2027-12-24', 'Christmas Eve', 'special'],
            ['2027-12-25', 'Christmas Day', 'regular'],
            ['2027-12-30', 'Rizal Day', 'regular'],
            ['2027-12-31', 'Last Day of the Year', 'special'],
        ];

        $now = now();

        DB::table('holidays')->insertOrIgnore(array_map(fn (array $holiday) => [
            'date' => $holiday[0],
            'name' => $holiday[1],
            'type' => $holiday[2],
            'created_at' => $now,
            'updated_at' => $now,
        ], $holidays));
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
