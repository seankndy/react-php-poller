<?php
namespace SeanKndy\Poller\Checks;

use React\Promise\PromiseInterface;
/**
 * Queue that stores Check's entirely in memory.
 *
 *
 */
class MemoryQueue implements QueueInterface
{
    /**
     * Check objects (sorted by priority) queued
     *
     * @var array
     */
    protected $queuedChecks = [];
    /**
     * Priorities, aka next check timestamps
     *
     * @var array
     */
    protected $queuePriorities = [];
    /**
     * Minimum priority contained
     *
     * @var int
     */
    protected $queueMin = PHP_INT_MAX;
    /**
     * Total queued
     *
     * @var int
     */
    protected $queueTot = 0;
    /**
     * {@inheritDoc}
     */
    public function dequeue() : PromiseInterface
    {
        while (isset($this->queuedChecks[$this->queueMin])) {
            $check = current($this->queuedChecks[$this->queueMin]);
            if (!$check->isDue()) {
                 /* if top Check is not due, then nothing is due. stop. */
                break;
            }

            // move internal cursor
            if (next($this->queuedChecks[$this->queueMin]) === false) {
                unset($this->queuePriorities[$this->queueMin]);
                unset($this->queuedChecks[$this->queueMin]);
                $this->queueMin = empty($this->queuePriorities) ? PHP_INT_MAX : \min($this->queuePriorities);
            }
            --$this->queueTot;

            return \React\Promise\resolve($check);
        }
        return \React\Promise\resolve(null);
    }

    /**
     * {@inheritDoc}
     */
    public function enqueue(Check $check) : PromiseInterface
    {
        $priority = $check->timeOfNextCheck();
        if (!is_int($priority) || $priority < 1) {
            return \React\Promise\reject(new \OutOfRangeException("The Check's " .
                "timeOfNextCheck() must return a positive integer"));
        }
        if (!isset($this->queuedChecks[$priority])) {
            $this->queuedChecks[$priority] = [];
        }
        $this->queuedChecks[$priority][] = $check;

        if (!isset($this->queuePriorities[$priority])) {
            $this->queuePriorities[$priority] = $priority;
            $this->queueMin = \min($priority, $this->queueMin);
        }
        ++$this->queueTot;
        return \React\Promise\resolve([]);
    }

    /**
     * {@inheritDoc}
     */
    public function countQueued() : PromiseInterface
    {
        return \React\Promise\resolve($this->queueTot);
    }

    /**
     * {@inheritDoc}
     */
    public function getQueued() : PromiseInterface
    {
        $queued = [];
        foreach (\array_keys($this->queuePriorities) as $priority) {
            $queued = \array_merge($queued, $this->queuedChecks[$priority]);
        }
        return \React\Promise\resolve($queued);
    }

    /**
     * {@inheritDoc}
     */
    public function flush() : PromiseInterface
    {
        return \React\Promise\resolve([]);
    }
}
