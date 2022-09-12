<?php
namespace SeanKndy\Poller\Results\Handlers;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;

/**
 * What a dummy.
 */
class Dummy implements HandlerInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(Check $check, Result $result, ?Incident $newIncident = null): PromiseInterface
    {
        return \React\Promise\resolve([]);
    }

    /**
     * {@inheritDoc}
     */
    public function mutate(Check $check, Result $result, ?Incident $newIncident = null): PromiseInterface
    {
        return \React\Promise\resolve([]);
    }
}
