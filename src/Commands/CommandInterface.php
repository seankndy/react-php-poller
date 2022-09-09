<?php
namespace SeanKndy\Poller\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;

interface CommandInterface
{
    /**
     * Do the work of the command on behalf of $check, produce a Result in a Promise
     */
    public function run(Check $check): PromiseInterface;

    /**
     * Return array of ResultMetrics that the Command can in theory produce in
     * a Result.
     */
    public function getProducableMetrics(array $attributes): array;
}
