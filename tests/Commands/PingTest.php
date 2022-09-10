<?php
namespace SeanKndy\Poller\Tests\Commands;

use React\EventLoop\Loop;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Commands\Ping;
use SeanKndy\Poller\Tests\TestCase;

class PingTest extends TestCase
{
    const HOST = '8.8.8.8';

    public function testRun()
    {
        $command = new Ping(Loop::get(), null);

        $attributes = [
            'ip' => self::HOST
        ];
        $check = new Check(1234, $command, $attributes, \time(), 10);
        $command->run($check)->then(
            $this->expectCallableOnceWith($this->isInstanceOf(Result::class)),
            $this->expectCallableNever()
        );

        Loop::get()->run();
    }
}
