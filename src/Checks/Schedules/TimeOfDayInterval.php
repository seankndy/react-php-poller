<?php

namespace SeanKndy\Poller\Checks\Schedules;

use Carbon\Carbon;

/**
 * This scheduler is constrained to a certain time of the day in a given timezone.
 * This is in contrast to RegularInterval that is due every <interval> seconds
 * regardless of timezone, time of day.
 *
 * For example, this is useful if you want to schedule a check every day at
 * 5pm (frequency = FREQUENCY_DAILY, interval = 1, offset = 17)
 * or every 6 hours 5 min past the hour: (frequency = FREQUENCY_HOURLY,
 * interval = 6, offset = 5)
 *
 */
final class TimeOfDayInterval implements ScheduleInterface
{
    const FREQUENCY_MINUTELY = 1;
    const FREQUENCY_HOURLY = 60;

    /**
     * Frequency (minutes)
     * @var int
     */
    private $frequency;
    /**
     * Interval of frequency
     * (e.g. every 2 minutes, every 4 hours, etc)
     * @var int
     */
    private $interval;
    /**
     * Offset
     * (e.g. if minutely, this means seconds past minute; if hourly, this means
     *  minutes past hour, etc)
     * @var string
     */
    private $offset;
    /**
     * Timezone
     * @var \DateTimeZone
     */
    private $timezone;

    public function __construct(int $frequency, int $interval = 1,
        int $offset = 0, string $timezone = 'UTC')
    {
        $this->timezone($timezone);
        $this->frequency($frequency);
        $this->interval($interval);
        $this->offset($offset);
    }

    /**
     * Is Check $check due according to schedule?
     *
     * @param Check $check
     * @return boolean
     */
    public function isDue(Check $check)
    {
        $now = new \DateTime('now', $this->timezone);
        $startAt = new \DateTime($this->startAt, $this->timezone);

        if ($now < $startAt) {
            return false;
        }
        if (!$check->getLastCheck()) {
            return true;
        }

        $lastCheck = new \DateTime('@'.$check->getLastCheck(), $this->timezone);
        $nextCheck = $lastCheck->add(new \DateInterval('PT'.$.'S'));

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
        $dayBegin = new \DateTime('00:00:00', $this->timezone);
        $dayEnd = new \DateTime('23:59:59', $this->timezone);
        $interval = new \DateInterval('PT'.$this->secondInterval().'S');
        $period = new \DatePeriod($dayBegin, $interval, $dayEnd);
        foreach ($period as $date) {
            ;
        }

        return !$check->getLastCheck() ? Carbon::now()->getTimestamp() : $check->getLastCheck()+$this->interval;
    }

    public function secondInterval(): int
    {
        return $this->frequency * $this->interval * 60;
    }

    public function interval(int $interval): self
    {
        if ($this->frequency == self::FREQUENCY_MINUTELY && ($this->interval < 1 || $this->interval > 1439)) {
            throw new \InvalidArgumentException("Invalid interval given, must be between 1 and 1440 for minutely frequency.");
        } else if ($this->frequency == self::FREQUENCY_HOURLY && ($this->interval < 1 || $this->interval > 23)) {
                throw new \InvalidArgumentException("Invalid interval given, must be between 1 and 1440 for minutely frequency.");
            } else if ()
        $this->interval = $interval;
        return $this;
    }

    /**
     * Set offset
     */
    public function offset(int $offset): self
    {
        if ($offset < 0 || $offset > 59) {
            throw new \InvalidArgumentException("Invalid offset given (0-59).");
        }
        $this->offset = $offset;

        return $this;
    }

    /**
     * Set timezone
     */
    public function timezone(string $timezone): self
    {
        $this->timezone = new \DateTimeZone($timezone);

        return $this;
    }

    /**
     * Set frequency
     */
    public function frequency(int $frequency): self
    {
        if (!\in_array($frequency, [self::FREQUENCY_MINUTLEY, self::FREQUENCY_HOURLY])) {
            throw new \InvalidArgumentException("Invalid frequency given.");
        }
        $this->frequency = $frequency;

        return $this;
    }
}
