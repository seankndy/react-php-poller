<?php

namespace SeanKndy\Poller\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;

interface CommandInterface
{
    /**
     * Do the work of the command on behalf of $check, produce a Result in a Promise
     *
     * @return PromiseInterface  Return a Promise<Result,\Exception>
     */
    public function run(Check $check): PromiseInterface;

    /**
     * Return array of ResultMetrics that the Command can in theory produce in
     * a Result.
     */
    public function getProducableMetrics(array $attributes): array;
}
