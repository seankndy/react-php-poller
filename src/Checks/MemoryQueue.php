<?php

namespace SeanKndy\Poller\Checks;

use React\Promise\PromiseInterface;

/**
 * Queue that stores Check's entirely in memory.
 */
class MemoryQueue implements QueueInterface
{
    /**
     * Check objects (sorted by priority) queued
     * @var array<int, array<Check>>
     */
    protected array $queuedChecks = [];

    /**
     * Priorities, aka next check timestamps
     *
     * @var array<int, int>
     */
    protected array $queuePriorities = [];

    /**
     * Minimum priority contained
     */
    protected int $queueMin = PHP_INT_MAX;

    /**
     * Total queued
     */
    protected int $queueTot = 0;

    /**
     * {@inheritDoc}
     */
    public function dequeue(): PromiseInterface
    {
        while (isset($this->queuedChecks[$this->queueMin])) {
            /** @var Check */
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

    public function enqueue(Check $check): PromiseInterface
    {
        $priority = $check->getNextCheck();
        if (!is_int($priority) || $priority < 1) {
            return \React\Promise\reject(new \OutOfRangeException("The Check's " .
                "getNextCheck() must return a positive integer"));
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

    public function countQueued(): PromiseInterface
    {
        return \React\Promise\resolve($this->queueTot);
    }

    public function getQueued(): PromiseInterface
    {
        $queued = [];
        foreach (\array_keys($this->queuePriorities) as $priority) {
            $queued = \array_merge($queued, $this->queuedChecks[$priority]);
        }

        return \React\Promise\resolve($queued);
    }

    public function flush(): PromiseInterface
    {
        return \React\Promise\resolve([]);
    }
}
