<?php

namespace SeanKndy\Poller\Tests\Checks;

use Carbon\Carbon;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Tests\TestCase;
use Spatie\TestTime\TestTime;

class CheckTest extends TestCase
{
    /** @test */
    public function it_is_due_when_schedule_is_due()
    {
        $check = (new Check(1))
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

        $this->assertTrue($check->isDue());
    }

    /** @test */
    public function it_is_not_due_when_schedule_is_not_due()
    {
        $check = (new Check(1))
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp());

        $this->assertFalse($check->isDue());
    }

    /** @test */
    public function it_has_next_check_time_equal_to_now_if_schedule_null()
    {
        TestTime::freeze();

        $check = (new Check(1))
            ->withSchedule(null)
            ->setLastCheck(Carbon::now()->getTimestamp());

        $this->assertEquals(Carbon::now()->getTimestamp(), $check->getNextCheck());

        TestTime::unfreeze();
    }

    /** @test */
    public function it_has_next_check_time_equal_schedules_due_time()
    {
        TestTime::freeze();

        $schedule = new Periodic(10);

        $check = (new Check(1))
            ->withSchedule($schedule)
            ->setLastCheck(Carbon::now()->getTimestamp());

        $this->assertEquals($schedule->timeDue($check), $check->getNextCheck());

        TestTime::unfreeze();
    }
}