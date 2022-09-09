<?php

namespace SeanKndy\Poller\Tests\Checks;

use Carbon\Carbon;
use PHPUnit\Util\Test;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Executor;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Tests\Commands\DummyCommand;
use SeanKndy\Poller\Tests\TestCase;
use Spatie\TestTime\TestTime;
use function React\Async\await;

class CheckTest extends TestCase
{
    /** @test */
    public function it_is_due_when_next_check_time_is_in_the_past()
    {
        TestTime::freeze();
        TestTime::subSecond();

        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            10
        );

        TestTime::addSecond();

        $this->assertTrue($check->isDue());

        TestTime::unfreeze();
    }

    /** @test */
    public function it_is_due_when_next_check_time_is_now()
    {
        TestTime::freeze();

        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            10
        );

        $this->assertTrue($check->isDue());

        TestTime::unfreeze();
    }

    /** @test */
    public function it_is_not_due_when_next_check_time_is_in_the_future()
    {
        TestTime::freeze();

        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            10
        );

        TestTime::subSecond();

        $this->assertFalse($check->isDue());

        TestTime::unfreeze();
    }

    /** @test */
    public function it_sets_next_check_to_current_time_if_time_given_is_in_past()
    {
        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            10
        );

        TestTime::freeze();
        $check->setNextCheck(Carbon::now()->getTimestamp() - 10);

        $this->assertEquals(Carbon::now()->getTimestamp(), $check->getNextCheck());

        TestTime::unfreeze();
    }

    /** @test */
    public function it_sets_next_check_to_time_given_if_time_given_is_in_future()
    {
        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            10
        );

        TestTime::freeze();
        $nextCheckTime = Carbon::now()->getTimestamp() + 10;
        $check->setNextCheck($nextCheckTime);

        $this->assertEquals($nextCheckTime, $check->getNextCheck());

        TestTime::unfreeze();
    }

    /** @test */
    public function it_throws_exception_when_run_without_command()
    {
        $this->expectException(\RuntimeException::class);

        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10);

        await($check->run());
    }

    /** @test */
    public function it_increments_next_check_by_interval_when_run()
    {
        $time = Carbon::now()->getTimestamp();
        $interval = 10;
        $check = new Check(1, new DummyCommand(), [], $time, $interval);
        $this->assertEquals($time, $check->getNextCheck());

        await($check->run());

        $this->assertEquals($time+$interval, $check->getNextCheck());
    }
}