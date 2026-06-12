<?php

declare(strict_types=1);

use App\Enums\TimestampTypeEnum;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Jobs\CalculateWeekBalance;
use App\Jobs\MenubarRefresh;
use App\Models\Project;
use App\Models\Timestamp;
use App\Services\TimestampService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Native\Desktop\Facades\MenuBar;

afterEach(function (): void {
    Date::setTestNow();
});

function useUtcTimeSettings(): void
{
    $settings = resolve(GeneralSettings::class);
    $settings->locale = 'en_US';
    $settings->timezone = 'UTC';
    $settings->save();

    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
}

it('counts an open work timestamp only inside the requested day', function (): void {
    Date::setTestNow('2025-01-15 01:06:45');

    Timestamp::create([
        'type' => TimestampTypeEnum::WORK,
        'started_at' => Date::parse('2025-01-14 09:00:00'),
        'last_ping_at' => Date::parse('2025-01-15 01:06:00'),
    ]);

    expect(TimestampService::getWorkTime())->toBe(4005.0);
});

it('splits active work timestamps from previous days at the current day boundary', function (): void {
    Date::setTestNow('2025-01-15 01:06:45');
    Bus::fake([CalculateWeekBalance::class]);

    $project = Project::create([
        'name' => 'Project Beta',
        'color' => '#123456',
    ]);

    $timestamp = Timestamp::create([
        'type' => TimestampTypeEnum::WORK,
        'project_id' => $project->id,
        'description' => 'Deep work',
        'started_at' => Date::parse('2025-01-13 09:00:00'),
        'last_ping_at' => Date::parse('2025-01-15 01:00:00'),
    ]);

    TimestampService::ping();

    $timestamp = $timestamp->fresh();
    $completedTimestamp = Timestamp::whereNotNull('ended_at')
        ->whereDate('started_at', '2025-01-14')
        ->sole();
    $activeTimestamp = Timestamp::whereNull('ended_at')->sole();

    expect($timestamp->ended_at->format('Y-m-d H:i:s'))->toBe('2025-01-13 23:59:59')
        ->and($timestamp->last_ping_at->format('Y-m-d H:i:s'))->toBe('2025-01-13 23:59:59')
        ->and($completedTimestamp->started_at->format('Y-m-d H:i:s'))->toBe('2025-01-14 00:00:00')
        ->and($completedTimestamp->ended_at->format('Y-m-d H:i:s'))->toBe('2025-01-14 23:59:59')
        ->and($activeTimestamp->started_at->format('Y-m-d H:i:s'))->toBe('2025-01-15 00:00:00')
        ->and($activeTimestamp->last_ping_at->format('Y-m-d H:i:s'))->toBe('2025-01-15 01:06:45')
        ->and($activeTimestamp->project_id)->toBe($project->id)
        ->and($activeTimestamp->description)->toBe('Deep work')
        ->and(TimestampService::getWorkTime())->toBe(4005.0);

    Bus::assertDispatched(CalculateWeekBalance::class);
});

it('resets today work time only when the reset action is posted', function (): void {
    Date::setTestNow('2025-01-15 11:00:00');

    Timestamp::create([
        'type' => TimestampTypeEnum::WORK,
        'started_at' => Date::parse('2025-01-15 09:00:00'),
        'ended_at' => Date::parse('2025-01-15 10:00:00'),
        'last_ping_at' => Date::parse('2025-01-15 10:00:00'),
    ]);

    Timestamp::create([
        'type' => TimestampTypeEnum::BREAK,
        'started_at' => Date::parse('2025-01-15 10:00:00'),
        'ended_at' => Date::parse('2025-01-15 10:15:00'),
        'last_ping_at' => Date::parse('2025-01-15 10:15:00'),
    ]);

    Timestamp::create([
        'type' => TimestampTypeEnum::WORK,
        'started_at' => Date::parse('2025-01-15 10:15:00'),
        'last_ping_at' => Date::parse('2025-01-15 10:59:00'),
    ]);

    expect(TimestampService::getWorkTime())->toBe(6300.0)
        ->and(TimestampService::getBreakTime())->toBe(900.0);

    $this->withoutMiddleware(SetLocaleMiddleware::class);

    $this->post(route('menubar.resetWorkTime'))->assertRedirect(route('menubar.index'));

    $activeTimestamp = Timestamp::whereNull('ended_at')->sole();

    expect(TimestampService::getWorkTime())->toBe(0.0)
        ->and(TimestampService::getBreakTime())->toBe(900.0)
        ->and($activeTimestamp->type)->toBe(TimestampTypeEnum::WORK)
        ->and($activeTimestamp->started_at->format('Y-m-d H:i:s'))->toBe('2025-01-15 11:00:00')
        ->and($activeTimestamp->last_ping_at->format('Y-m-d H:i:s'))->toBe('2025-01-15 11:00:00');
});

it('keeps previous work time when continuing after a break', function (): void {
    useUtcTimeSettings();

    Date::setTestNow('2025-01-15 09:00:00');

    TimestampService::startWork();

    Date::setTestNow('2025-01-15 10:55:00');

    expect(TimestampService::getWorkTime())->toBe(6900.0);

    TimestampService::startBreak();

    Date::setTestNow('2025-01-15 11:10:00');

    expect(TimestampService::getWorkTime())->toBe(6900.0)
        ->and(TimestampService::getBreakTime())->toBe(900.0);

    TimestampService::startWork();

    expect(TimestampService::getWorkTime())->toBe(6900.0)
        ->and(TimestampService::getBreakTime())->toBe(900.0)
        ->and(Timestamp::query()->whereNull('ended_at')->sole()->type)->toBe(TimestampTypeEnum::WORK);
});

it('keeps current day work time when starting a break after work crossed midnight', function (): void {
    useUtcTimeSettings();

    Date::setTestNow('2025-01-15 01:55:00');

    Timestamp::create([
        'type' => TimestampTypeEnum::WORK,
        'started_at' => Date::parse('2025-01-14 22:00:00'),
        'last_ping_at' => Date::parse('2025-01-15 00:55:00'),
    ]);

    expect(TimestampService::getWorkTime())->toBe(6900.0);

    TimestampService::startBreak();

    expect(TimestampService::getWorkTime())->toBe(6900.0)
        ->and(Timestamp::query()->whereNull('ended_at')->sole()->type)->toBe(TimestampTypeEnum::BREAK);

    Date::setTestNow('2025-01-15 02:10:00');

    TimestampService::startWork();

    expect(TimestampService::getWorkTime())->toBe(6900.0)
        ->and(TimestampService::getBreakTime())->toBe(900.0)
        ->and(Timestamp::query()->whereNull('ended_at')->sole()->type)->toBe(TimestampTypeEnum::WORK);
});

it('refreshes the native menu bar label with a compact duration and full tooltip', function (): void {
    $settings = resolve(GeneralSettings::class);
    $settings->locale = 'en_US';
    $settings->timezone = 'America/Los_Angeles';
    $settings->save();

    config(['app.timezone' => $settings->timezone]);
    date_default_timezone_set($settings->timezone);

    Date::setTestNow(Date::parse('2025-01-15 00:06:15'));

    Timestamp::create([
        'type' => TimestampTypeEnum::WORK,
        'started_at' => Date::parse('2025-01-15 00:00:00'),
        'last_ping_at' => Date::parse('2025-01-15 00:06:00'),
    ]);

    MenuBar::shouldReceive('icon')->once();
    MenuBar::shouldReceive('tooltip')->once()->with('00:06:15');
    MenuBar::shouldReceive('label')->once()->with('0:06');

    (new MenubarRefresh)->handle();

    expect(MenubarRefresh::formatDuration(TimestampService::getWorkTime()))->toBe('00:06:15')
        ->and(MenubarRefresh::formatLabelDuration(47132))->toBe('13:05');
});
