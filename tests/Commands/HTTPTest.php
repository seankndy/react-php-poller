<?php
namespace SeanKndy\Poller\Tests\Commands;

use React\EventLoop\Loop;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Commands\HTTP;
use SeanKndy\Poller\Tests\TestCase;

class HTTPTest extends TestCase
{
    const SITE = 'www.google.com';

    public function testRun()
    {
        $loop = Loop::get();
        $command = new HTTP($loop);

        $attributes = [
            'host' => self::SITE,
            'ssl' => true
        ];
        $check = new Check(1234, $command, $attributes, \time()-10, new Periodic(10));
        $command->run($check)->then(
            $this->expectCallableOnceWith($this->isInstanceOf(Result::class)),
            $this->expectCallableNever()
        );

        $loop->run();
    }
}
