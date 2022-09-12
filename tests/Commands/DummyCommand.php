<?php

namespace SeanKndy\Poller\Tests\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Commands\CommandInterface;
use SeanKndy\Poller\Results\Result;

class DummyCommand implements CommandInterface
{
    private bool $failRun;

    private Result $result;

    public function __construct($failRun = false, ?Result $result = null)
    {
        $this->failRun = $failRun;
        if (!$result) {
            $result = new Result();
        }
        $this->result = $result;
    }

    public function run(Check $check): PromiseInterface
    {
        return $this->failRun
            ? \React\Promise\reject(new \Exception("Oops."))
            : \React\Promise\resolve($this->result);
    }

    public function getProducableMetrics(array $attributes): array
    {
        return [];
    }
}