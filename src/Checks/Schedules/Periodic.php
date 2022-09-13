<?php

namespace SeanKndy\Poller\Checks\Schedules;

use Carbon\Carbon;
use SeanKndy\Poller\Checks\Check;

/**
 * This scheduler becomes "due" at a fixed interval (example: every 60 seconds).
 */
final class Periodic implements ScheduleInterface
{
    /**
     * Interval in seconds.
     */
    private int $interval;

    public function __construct(int $interval)
    {
        $this->interval = $interval;
    }

    public function isDue(Check $check): bool
    {
        return $this->secondsUntilDue($check) <= 0;
    }

    public function secondsUntilDue(Check $check): int
    {
        return !$check->getLastCheck() ? 0 : ($check->getLastCheck() + $this->interval) - Carbon::now()->getTimestamp();
    }

    public function getInterval(): int
    {
        return $this->interval;
    }
}
