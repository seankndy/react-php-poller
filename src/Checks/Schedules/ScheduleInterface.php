<?php
namespace SeanKndy\Poller\Checks;

interface ScheduleInterface
{
    /**
     * Is Check $check due according to schedule?
     *
     * @param Check $check
     * @return boolean
     */
    public function isDue(Check $check);

    /**
     * Time of next check
     *
     * @param Check $check
     * @return int UNIX timestamp
     */
    public function nextTimeIsDue(Check $check);

    /**
     * Get interval in seconds
     *
     * @return int
     */
    public function getInterval();
}
