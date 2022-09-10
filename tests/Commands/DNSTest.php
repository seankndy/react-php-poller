<?php
namespace SeanKndy\Poller\Tests\Commands;

use React\EventLoop\Loop;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Commands\DNS;
use SeanKndy\Poller\Tests\TestCase;

class DNSTest extends TestCase
{
    /** @test */
    public function it_successfully_runs_dns_check()
    {
        $command = new DNS(Loop::get());

        $check = new Check(1234, $command, ['lookup_hostname' => 'google.com'], \time(), 10);
        $command->run($check)->then($this->expectCallableOnce(), $this->expectCallableNever());

        Loop::get()->run();
    }
}
