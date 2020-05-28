<?php
namespace SeanKndy\Poller\Checks;

use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Handlers\HandlerInterface;
use SeanKndy\Poller\Commands\CommandInterface;

interface Checkable
{
    /**
     * Is this checkable due to be checked?
     *
     * @param $time Timestamp of now
     * @return boolean
     */
    public function isDue(int $time = null);
}
