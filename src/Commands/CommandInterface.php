<?php
namespace SeanKndy\Poller\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;

interface CommandInterface
{
    /**
     * Do the work of the command on behalf of $check, produce a Result in a Promise
     *
     * @param Check $check
     *
     * @return \React\Promise\Promise
     */
    public function run(Check $check);

    /**
     * Return array of ResultMetrics that the Command can in theory produce in
     * a Result.
     *
     * @return array
     */
    public function getProducableMetrics(array $attributes);
}
