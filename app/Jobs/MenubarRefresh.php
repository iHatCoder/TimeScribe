<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TimestampTypeEnum;
use App\Services\LocaleService;
use App\Services\TimestampService;
use App\Services\TrayIconService;
use Native\Desktop\Facades\MenuBar;

class MenubarRefresh
{
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            new LocaleService;
        } catch (\Throwable) {
            //
        }

        try {
            $currentType = TimestampService::getCurrentType();

            if ($currentType === TimestampTypeEnum::WORK) {
                $time = TimestampService::getWorkTime();
                MenuBar::icon(TrayIconService::getIcon('work'));
            } elseif ($currentType === TimestampTypeEnum::BREAK) {
                $time = TimestampService::getBreakTime();
                MenuBar::icon(TrayIconService::getIcon('break'));
            } else {
                MenuBar::tooltip('');
                MenuBar::label('');
                MenuBar::icon(TrayIconService::getIcon());

                return;
            }

            $duration = self::formatDuration($time);

            MenuBar::tooltip($duration);
            MenuBar::label($duration);
        } catch (\Throwable) {
            return;
        }
    }

    public static function formatDuration(float|int $seconds): string
    {
        $seconds = max(0, (int) floor($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }
}
