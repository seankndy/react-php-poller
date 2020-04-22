<?php
namespace SeanKndy\Poller\Tests\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Commands\HTTP;
use SeanKndy\Poller\Tests\TestCase;

class HTTPTest extends TestCase
{
    const SITE = 'www.google.com';

    public function testRun()
    {
        $loop = \React\EventLoop\Factory::create();
        $command = new HTTP($loop);

        $attributes = [
            'ip' => self::SITE
        ];
        $check = new Check(1234, $command, $attributes, \time(), 10);
        $command->run($check)->then(
            $this->expectCallableOnceWith($this->isInstanceOf(Result::class)),
            $this->expectCallableNever()
        );

        $loop->run();
    }
}
