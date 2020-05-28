<?php
namespace SeanKndy\Poller\Checks\Schedules;

final class TimeAware implements ScheduleInterface
{
    const FREQUENCY_MINUTELY = 1;
    const FREQUENCY_HOURLY = 60;
    const FREQUENCY_DAILY = 60*24;
    const FREQUENCY_WEEKLY = 60*24*7;

    /**
     * Frequency
     * @var int
     */
    private $frequency;
    /**
     * Interval of frequency
     * (e.g. every 2 minutes, every 4 days, every 2 weeks, etc)
     * @var int
     */
    private $interval;
    /**
     * Offset (seconds) past the minute, hour, day or week
     * @var int
     */
    private $offset;
    /**
     * Start of schedule
     * @var int
     */
    private $startAt;

    public function __construct(int $startAt, int $frequency, int $interval = 1,
        int $offset = 0)
    {
        $this->startAt = $startAt;
        $this->frequency = $frequency;
        $this->interval = $interval;
        $this->offset = $offset;
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

        if ($time < $this->startAt) {
            return false;
        }

        return $time >= $this->nextTimeIsDue($check);

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
        return $this->frequency * $this->interval * 60;
    }
}
