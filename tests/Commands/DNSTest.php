<?php
namespace SeanKndy\Poller\Tests\Commands;

use Carbon\Carbon;
use React\EventLoop\Loop;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Commands\DNS;
use SeanKndy\Poller\Tests\TestCase;

class DNSTest extends TestCase
{
    /** @test */
    public function it_successfully_runs_dns_check()
    {
        $command = new DNS(Loop::get());

        $check = (new Check(1234))
            ->withCommand($command)
            ->withAttributes([
                'lookup_hostname' => 'google.com'
            ]);

        $command->run($check)->then($this->expectCallableOnce(), $this->expectCallableNever());

        Loop::get()->run();
    }
}
