<?php
namespace SeanKndy\Poller\Checks;

use \React\Promise\PromiseInterface;

/**
 * Queue interface for queueing Check objects.
 *
 * The queuing functionality should be an inverse priority queue where the
 * priority is the timestamp of the next scheduled check and thus the smallest
 * timestamp is nearest the top of the queue.
 *
 */
interface QueueInterface
{
    /**
     * Add a Check to the queue
     *
     * @param Check $check  Check to queue
     *
     * @return void
     */
    public function enqueue(Check $check) : PromiseInterface;

    /**
     * Extract next Check that is DUE from queue. Note that this command
     * must not unconditionally pop the next Check off a stack. The Check
     * must be due for checking.
     * Returns a Promise whose value is the Check or else null
     *
     * @return PromiseInterface Returns a Promise<Check,\Exception>
     */
    public function dequeue() : PromiseInterface;

    /**
     * Return number of Checks queued
     *
     * @return PromiseInterface Returns a Promise<int,\Exception>
     */
    public function countQueued() : PromiseInterface;

    /**
     * Get all queued Checks
     *
     * @return PromiseInterface Returns a Promise<Check[],\Exception>
     */
    public function getQueued() : PromiseInterface;

    /**
     * Request that cached queue data is flushed to disk.
     * For example, this will likely be called just before the script
     * is killed.
     *
     * @return PromiseInterface Returns a Promise<void,\Exception>
     */
    public function flush() : PromiseInterface;
}
