<?php

namespace SeanKndy\Poller\Results\Handlers;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;

interface HandlerInterface
{
    /**
     * Handle Result $result/Incident $incident synchronously to allow mutations on
     * $check, $result or $incident.
     * This method SHOULD NOT make long running async I/O calls and instead be restricted
     * to doing any data mutating necessary, but that doesn't mean that it can't
     * have some I/O calls as long as they're very responsive.
     *
     * @param Check $check Check object for the Result
     * @param Result $result Current/new Result to process
     * @param Incident|null $newIncident Current/new Incident to process
     *
     * @return PromiseInterface
     */
    public function mutate(Check $check, Result $result, ?Incident $newIncident = null): PromiseInterface;

    /**
     * Handle Result $result/Incident $incident  asynchronously to allow for various result
     * data processin/storage.  This method MAY be called with clones of the argument objects,
     * so the implementor should treat them as immutable.
     *
     * @param Check $check (possibly cloned) Check object for the Result
     * @param Result $result (possibly cloned) Current/new Result to process
     * @param Incident|null $newIncident (possibly cloned) Current/new Incident to process
     *
     * @return PromiseInterface
     */
    public function process(Check $check, Result $result, ?Incident $newIncident = null): PromiseInterface;
}
