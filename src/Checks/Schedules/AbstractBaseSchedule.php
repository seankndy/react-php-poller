<?php

namespace SeanKndy\Poller\Checks\Schedules;

use Carbon\Carbon;
use SeanKndy\Poller\Checks\Check;

abstract class AbstractBaseSchedule implements ScheduleInterface
{
    public function isDue(Check $check): bool
    {
        $now = Carbon::now()->getTimestamp();

        return $now - $this->timeDue($check) >= 0;
    }
}