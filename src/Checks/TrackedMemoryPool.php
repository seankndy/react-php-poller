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
     * @var array
     */
    protected $trackedChecks = [];

    /**
     * Track and optionally auto queue a new Check in the pool
     *
     * @param Check $check  Check to insert
     * @param bool $autoQueue Should enqueue() be called automatically
     *
     * @return void
     */
    public function track(Check $check, bool $autoQueue = true) : void
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
     *
     * @return void
     */
    public function untrack($id) : void
    {
        if (isset($this->trackedChecks[$id]))
            unset($this->trackedChecks[$id]);
    }

    /**
     * {@inheritDoc}
     */
    public function dequeue() : PromiseInterface
    {
        return parent::dequeue()->then(function ($check) {
            if ($check) {
                $deleted = !isset($this->trackedChecks[$check->getId()]);
                if ($deleted) {
                    // check has been dequeued(), but we dont release it to caller
                    // and instead call dequeue() again, effectively deleting it from existance.
                    return parent::dequeue();
                }
            }
            return $check;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function enqueue(Check $check) : PromiseInterface
    {
        if (!$this->getById($check->getId())) {
            return \React\Promise\reject(new \RuntimeException("Check with ID " .
                $check->getId() . " is not tracked thus cannot be queued"));
        }

        return parent::enqueue($check);
    }

    /**
     * Return number of Checks tracked
     *
     * @return int
     */
    public function countTracked() : int
    {
        return count($this->trackedChecks);
    }

    /**
     * Get all tracked Checks
     *
     * @return Check[]
     */
    public function getTracked() : array
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
    public function getById($id) : ?Check
    {
        return isset($this->trackedChecks[$id]) ? $this->trackedChecks[$id] : null;
    }
}
