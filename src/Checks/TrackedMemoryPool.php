<?php

namespace SeanKndy\Poller\Checks;

use React\Promise\PromiseInterface;

/**
 * Pool to store active Check objects in memory (internal array)
 *
 * There are 2 states for the pool:
 *  1) Tracked in pool, but not queued (i.e. currently not queued because Check
 *     is running)
 *  2) Tracked in pool and queued (i.e. currently queued because Check is idle)
 *
 * There must be the concept of 'tracked/in pool' versus 'queued' because
 * Checks can dequeue/queue from the pool and because there is no permanent
 * storage (i.e. in a DB or Redis) of a MemoryPool, users of this object
 * need a way to verify that a Check exists in the pool or not.
 *
 * track(Check, bool) inserts a Check into the Pool to be 'tracked' and optionally
 *                    auto-enqueues()s it.
 * untrack(mixed) removes Check from Pool
 * dequeue() returns a queued Check *IF* it's due to be checked, otherwise null
 *           is returned
 * enqueue(Check $c) queues the Check $c, must first be track()ed
 *
 *
 */
class TrackedMemoryPool extends MemoryQueue
{
    /**
     * Checks being tracked in this pool
     * Similar to $queuedChecks except $trackedChecks is indexed by check ID
     * and is constant unless untrack() is called.
     *
     * @var Check[]
     */
    protected array $trackedChecks = [];

    /**
     * Track and optionally auto queue a new Check in the pool
     *
     * @param Check $check  Check to insert
     * @param bool $autoQueue Should enqueue() be called automatically
     *
     * @return void
     */
    public function track(Check $check, bool $autoQueue = true): void
    {
        if ($check->getId() === '') {
            throw new \RuntimeException("Check must have an ID set");
        }
        if ($this->getById($check->getId())) {
            throw new \RuntimeException("Check with ID " . $check->getId() . " is already tracked in pool");
        }
        $this->trackedChecks[$check->getId()] = $check;
        if ($autoQueue)
            $this->enqueue($check);
    }

    /**
     * Untrack a Check from the pool
     *
     * @param mixed $id  ID of Check to untrack
     */
    public function untrack($id): void
    {
        if (isset($this->trackedChecks[$id]))
            unset($this->trackedChecks[$id]);
    }

    /**
     * {@inheritDoc}
     */
    public function dequeue(): PromiseInterface
    {
        return parent::dequeue()->then(function ($check) {
            if ($check) {
                $deleted = !isset($this->trackedChecks[$check->getId()]);
                if ($deleted) {
                    // check is not tracked; if it's interval is >0 then we
                    // want to just delete this check from existence, so we
                    // will call dequeue() again to effectively do that.
                    // however, if it's interval == 0, then release it to caller
                    // because caller shouldn't ever re-enqueue being that it's
                    // interval = 0
                    return $check->getInterval() > 0 ? parent::dequeue() : $check;
                }
            }
            return $check;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function enqueue(Check $check): PromiseInterface
    {
        if ($check->getInterval() > 0 && !$this->getById($check->getId())) {
            return \React\Promise\reject(new \RuntimeException("Check with ID " .
                $check->getId() . " is not tracked nor is it a transient check " .
                "(interval <=0) thus cannot be queued in this queue."));
        }

        return parent::enqueue($check);
    }

    /**
     * Return number of Checks tracked
     */
    public function countTracked(): int
    {
        return count($this->trackedChecks);
    }

    /**
     * Get all tracked Checks
     *
     * @return Check[]
     */
    public function getTracked(): array
    {
        return \array_values($this->trackedChecks);
    }

    /**
     * Get Check from pool with ID $id
     *
     * @param mixed $id  ID of Check to fetch
     *
     * @return Check|null
     */
    public function getById($id): ?Check
    {
        return $this->trackedChecks[$id] ?? null;
    }
}
