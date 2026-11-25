<?php

declare(strict_types=1);

use Infocyph\Foundation\Scheduling\Schedule;

return static function (Schedule $schedule): void {
    $schedule->command('lab:report')->key('lab.report')->everyMinute()
        ->onOneServer(true, 30.0, 0.0)->withoutOverlap(true, 30.0, 0.0);
};
