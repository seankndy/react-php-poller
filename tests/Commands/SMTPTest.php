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

        $check = new Check(1234, $command, ['ip' => 'mx.vcn.com'], \time()-10, new Periodic(10));
        $command->run($check)->then($this->expectCallableOnce(), $this->expectCallableNever());

        $loop->run();
    }
}
