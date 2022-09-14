<?php
namespace SeanKndy\Poller\Tests\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Commands\SNMP;
use SeanKndy\Poller\Tests\TestCase;

class SNMPTest extends TestCase
{
    const SNMP_HOST = '209.193.82.100';
    const SNMP_IF_ID = 6;
    const SNMP_COMMUNITY = 'public';

    public function testRun()
    {
        $loop = \React\EventLoop\Factory::create();
        $command = new SNMP($loop);

        $attributes = [
            'ip' => self::SNMP_HOST,
            'snmp_read_community' => self::SNMP_COMMUNITY,
            'snmp_if_id' => self::SNMP_IF_ID
        ];

        $check = (new Check(1234))
            ->withCommand($command)
            ->withAttributes($attributes);

        $command->run($check)->then(
            $this->expectCallableOnceWith($this->isInstanceOf(Result::class)),
            $this->expectCallableNever()
        );

        $loop->run();
    }
}
