<?php
namespace SeanKndy\Poller\Tests\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Commands\DNS;
use SeanKndy\Poller\Tests\TestCase;

class DNSTest extends TestCase
{
    public function testRun()
    {
        $loop = \React\EventLoop\Factory::create();
        $command = new DNS($loop);

        $check = new Check(1234, $command, ['lookup_hostname' => 'google.com'], 10);
        $command->run($check)->then($this->expectCallableOnce(), $this->expectCallableNever());

        $check = new Check(1234, $command, ['ip' => '1.2.3.4', 'lookup_hostname' => 'google.com'], 10);
        $command->run($check)->then($this->expectCallableOnce(), $this->expectCallableNever());

        $loop->run();
    }
}
