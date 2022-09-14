<?php

namespace SeanKndy\Poller\Checks\Schedules;

use Carbon\Carbon;
use SeanKndy\Poller\Checks\Check;

/**
 * This scheduler becomes "due" at a fixed interval (example: every 60 seconds).
 */
final class Periodic extends AbstractBaseSchedule
{
    /**
     * Interval in seconds.
     */
    private int $interval;

    public function __construct(int $interval)
    {
        $this->interval = $interval;
    }

    public function timeDue(Check $check): int
    {
        $now = Carbon::now()->getTimestamp();

        return !$check->getLastCheck() ? $now : ($check->getLastCheck() + $this->interval);
    }

    public function getInterval(): int
    {
        return $this->interval;
    }
}
