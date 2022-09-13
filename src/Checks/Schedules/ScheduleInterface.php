<?php

namespace SeanKndy\Poller\Checks\Schedules;

use SeanKndy\Poller\Checks\Check;

interface ScheduleInterface
{
    /**
     * Is Check $check due according to the schedule?
     */
    public function isDue(Check $check): bool;

    /**
     * Seconds until Check $check will be due, or negative if past due.
     */
    public function secondsUntilDue(Check $check): int;
}