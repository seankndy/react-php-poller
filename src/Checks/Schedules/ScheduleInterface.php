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
     * Timestamp when Check $check will be next due (can be in past).
     */
    public function timeDue(Check $check): int;
}