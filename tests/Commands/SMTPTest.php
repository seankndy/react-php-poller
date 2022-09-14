<?php
namespace SeanKndy\Poller\Tests\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Commands\SMTP;
use SeanKndy\Poller\Tests\TestCase;

class SMTPTest extends TestCase
{
    public function testRun()
    {
        $loop = \React\EventLoop\Factory::create();
        $command = new SMTP($loop);

        $check = (new Check(1234))
            ->withCommand($command)
            ->withAttributes([
                'ip' => 'mx.vcn.com'
            ]);

        $command->run($check)->then($this->expectCallableOnce(), $this->expectCallableNever());

        $loop->run();
    }
}
