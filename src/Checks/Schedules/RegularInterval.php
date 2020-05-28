<?php
namespace SeanKndy\Poller\Checks\Schedules;

final class RegularInterval implements ScheduleInterface
{
    /**
     * Seconds between checks
     * @var int
     */
    private $interval;

    public function __construct(int $interval)
    {
        $this->interval = $interval;
    }

    /**
     * Is Check $check due according to schedule?
     *
     * @param Check $check
     * @return boolean
     */
    public function isDue(Check $check)
    {
        $time = \time();

        // true if check never ran or number of seconds since
        // last run is >= interval
        return (!$check->getLastCheck()
            || ($time - $check->getLastCheck()) >= $this->interval);
    }

    /**
     * Time of next check
     *
     * @param Check $check
     * @return int UNIX timestamp
     */
    public function nextTimeIsDue(Check $check)
    {
        return !$check->getLastCheck() ? \time() : $check->getLastCheck()+$this->interval;
    }

    /**
     * Get interval in seconds
     *
     * @return int
     */
    public function getInterval()
    {
        return $this->interval;
    }
}
