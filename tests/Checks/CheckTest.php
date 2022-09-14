<?php

namespace SeanKndy\Poller\Tests\Checks;

use Carbon\Carbon;
use PHPUnit\Util\Test;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Executor;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Tests\Commands\DummyCommand;
use SeanKndy\Poller\Tests\TestCase;
use Spatie\TestTime\TestTime;
use function React\Async\await;

class CheckTest extends TestCase
{
    /** @test */
    public function it_is_due_when_schedule_is_due()
    {
        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp()-10,
            new Periodic(10)
        );

        $this->assertTrue($check->isDue());
    }

    /** @test */
    public function it_is_not_due_when_schedule_is_not_due()
    {
        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            new Periodic(10)
        );

        $this->assertFalse($check->isDue());;
    }

    /** @test */
    public function it_throws_exception_when_run_without_command()
    {
        $this->expectException(\RuntimeException::class);

        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), new Periodic(10));

        await($check->run());
    }

    /** @test */
    public function it_sets_last_check_time_when_run()
    {
        TestTime::freeze();

        $interval = 10;
        $time = Carbon::now()->getTimestamp() - $interval;
        $check = new Check(1, new DummyCommand(), [], $time, new Periodic($interval));
        $this->assertEquals($time, $check->getLastCheck());

        TestTime::addSeconds($interval);

        await($check->run());

        $this->assertEquals(Carbon::now()->getTimestamp(), $check->getLastCheck());

        TestTime::unfreeze();
    }
}