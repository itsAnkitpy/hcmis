<?php

use App\Enums\ClosedHours;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * inbound-audio.md slice 1 — the office hours sign: "is this client closed right now?"
 *
 * One rule, read on the client's own clock: the weekday's hours, a closing time earlier
 * than the opening time read as the next morning (AU-6), equal times read as 24 hours
 * from the opening time (S164), holiday dates (AU-7), and a night shift that belongs to
 * the day it started (AUQ-2, S164). With the switch off the client is never closed (AU-1).
 *
 * No database: the rule reads only the client's settings and time zone.
 *
 * The week used: Sat 19 · Sun 20 · Mon 21 · Tue 22 September 2026.
 */

/**
 * @param  array<string, array{open: string, close: string}|null>  $hours
 * @param  array<int, string>  $holidays
 */
function closedHoursClient(array $hours, array $holidays = [], ClosedHours $closedHours = ClosedHours::NoPickup): Tenant
{
    return new Tenant([
        'timezone' => 'Asia/Kolkata',
        'settings' => ['hours' => $hours, 'holidays' => $holidays, 'closed_hours' => $closedHours->value],
    ]);
}

function onKolkataClock(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'Asia/Kolkata');
}

$weekdays = [
    'monday' => ['open' => '09:30', 'close' => '18:30'],
    'tuesday' => ['open' => '09:30', 'close' => '18:30'],
    'saturday' => null,
];

it('reads a normal day', function (string $moment, bool $closed) use ($weekdays) {
    expect(closedHoursClient($weekdays)->isClosedAt(onKolkataClock($moment)))->toBe($closed);
})->with([
    'inside the hours' => ['2026-09-21 10:00', false],
    'at opening' => ['2026-09-21 09:30', false],
    'before opening' => ['2026-09-21 08:00', true],
    'at closing' => ['2026-09-21 18:30', true],
    'a closed weekday' => ['2026-09-19 12:00', true],
    'a weekday with no hours saved' => ['2026-09-20 12:00', true],
]);

it('reads a night shift on both sides of midnight (AU-6)', function (string $moment, bool $closed) {
    $client = closedHoursClient(['sunday' => ['open' => '20:00', 'close' => '05:00']]);

    expect($client->isClosedAt(onKolkataClock($moment)))->toBe($closed);
})->with([
    'before the shift' => ['2026-09-20 19:59', true],
    'before midnight' => ['2026-09-20 23:00', false],
    'after midnight, next morning' => ['2026-09-21 02:00', false],
    'when it ends' => ['2026-09-21 05:00', true],
]);

it('reads equal opening and closing times as 24 hours from opening (S164)', function (array $hours, string $moment, bool $closed) {
    expect(closedHoursClient($hours)->isClosedAt(onKolkataClock($moment)))->toBe($closed);
})->with([
    'midnight to midnight, early' => [['saturday' => ['open' => '00:00', 'close' => '00:00']], '2026-09-19 00:00', false],
    'midnight to midnight, late' => [['saturday' => ['open' => '00:00', 'close' => '00:00']], '2026-09-19 23:59', false],
    'midnight to midnight, next day' => [['saturday' => ['open' => '00:00', 'close' => '00:00']], '2026-09-20 00:00', true],
    '09:00 to 09:00, next morning' => [['monday' => ['open' => '09:00', 'close' => '09:00']], '2026-09-22 08:59', false],
    '09:00 to 09:00, when it ends' => [['monday' => ['open' => '09:00', 'close' => '09:00']], '2026-09-22 09:00', true],
]);

it('is closed all day on a holiday (AU-7)', function () use ($weekdays) {
    expect(closedHoursClient($weekdays, ['2026-09-21'])->isClosedAt(onKolkataClock('2026-09-21 10:00')))->toBeTrue()
        ->and(closedHoursClient($weekdays, ['2026-09-21'])->isClosedAt(onKolkataClock('2026-09-22 10:00')))->toBeFalse();
});

it('lets a night shift that started the day before a holiday run on (AUQ-2)', function () {
    $nights = [
        'sunday' => ['open' => '20:00', 'close' => '05:00'],
        'monday' => ['open' => '20:00', 'close' => '05:00'],
    ];
    $client = closedHoursClient($nights, ['2026-09-21']);   // Monday is the holiday

    expect($client->isClosedAt(onKolkataClock('2026-09-21 02:00')))->toBeFalse()   // Sunday's shift
        ->and($client->isClosedAt(onKolkataClock('2026-09-21 21:00')))->toBeTrue()   // Monday's shift never starts
        ->and($client->isClosedAt(onKolkataClock('2026-09-22 02:00')))->toBeTrue();
});

it('reads the hours on the client\'s own clock, not the server\'s', function () use ($weekdays) {
    $client = closedHoursClient($weekdays);
    $client->timezone = 'America/New_York';

    // 14:00 UTC is 10:00 in New York (open) and 19:30 in Kolkata (closed).
    expect($client->isClosedAt(CarbonImmutable::parse('2026-09-21 14:00', 'UTC')))->toBeFalse();
});

it('is never closed while the switch is off (AU-1)', function () {
    $client = closedHoursClient(['saturday' => null], ['2026-09-19'], ClosedHours::Off);

    expect($client->isClosedAt(onKolkataClock('2026-09-19 12:00')))->toBeFalse();
});

it('is off for a client that never set it', function () {
    expect((new Tenant)->settings->closedHours)->toBe(ClosedHours::Off);
});
