<?php
namespace SeanKndy\Poller\Results\Handlers;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric;
use React\EventLoop\LoopInterface;
/**
 * What a dummy.
 */
class Dummy implements HandlerInterface
{
    /**
     * {@inheritDoc}
     */
    public function process(Check $check, Result $result, Incident $incident = null)
    {
        ;
    }

    /**
     * {@inheritDoc}
     */
    public function mutate(Check $check, Result $result, Incident $incident = null)
    {
        ;//$result->addMetric(new Metric(Metric::TYPE_GAUGE, 'sixtynine', '69.0'));
    }
}
