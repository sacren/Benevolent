<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

test('seeding the central database succeeds without an operator to seed', function (): void {
    // DatabaseSeeder used to create a central user. Operators live in their own
    // campaign's database, so once `users` leaves the central set that seed dies
    // with `relation "users" does not exist` -- and it dies in db:seed, which no
    // test exercised, so nothing would have caught it.
    //
    // This asserts the command completes rather than asserting what it wrote,
    // which is deliberate: the answer is that it writes nothing centrally, and
    // that stays true whether or not the central `users` table still exists.
    expect(Artisan::call('db:seed'))->toBe(Command::SUCCESS);
});
