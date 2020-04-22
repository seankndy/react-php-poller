<?php
namespace SeanKndy\Poller\Tests\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Commands\Ping;
use SeanKndy\Poller\Tests\TestCase;

class PingTest extends TestCase
{
    const HOST = '8.8.8.8';

    public function testRun()
    {
        $loop = \React\EventLoop\Factory::create();
        $command = new Ping($loop, null);

        $attributes = [
            'ip' => self::HOST
        ];
        $check = new Check(1234, $command, $attributes, \time(), 10);
        $command->run($check)->then(
            $this->expectCallableOnceWith($this->isInstanceOf(Result::class)),
            $this->expectCallableNever()
        );

        $loop->run();
    }
}
