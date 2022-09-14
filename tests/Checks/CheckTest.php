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
    public function it_has_next_check_time_equal_to_now_if_schedule_null()
    {
        TestTime::freeze();

        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            null
        );

        $this->assertEquals(Carbon::now()->getTimestamp(), $check->getNextCheck());

        TestTime::unfreeze();
    }

    /** @test */
    public function it_has_next_check_time_equal_schedules_due_time()
    {
        TestTime::freeze();

        $schedule = new Periodic(10);

        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            $schedule
        );

        $this->assertEquals($schedule->timeDue($check), $check->getNextCheck());

        TestTime::unfreeze();
    }
}