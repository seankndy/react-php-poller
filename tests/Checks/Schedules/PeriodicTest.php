<?php

namespace SeanKndy\Poller\Tests\Checks\Schedules;

use Carbon\Carbon;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Tests\TestCase;
use Spatie\TestTime\TestTime;

class PeriodicTest extends TestCase
{
    /** @test */
    public function it_is_due_when_checks_is_overdue(): void
    {
        $schedule = new Periodic(10);

        $check = (new Check(1234))
            ->withSchedule($schedule)
            ->setLastCheck(Carbon::now()->getTimestamp()-11);

        $this->assertTrue($schedule->isDue($check));
    }

    /** @test */
    public function it_is_due_when_check_is_exactly_due(): void
    {
        $schedule = new Periodic(10);

        $check = (new Check(1234))
            ->withSchedule($schedule)
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

        $this->assertTrue($schedule->isDue($check));
    }

    /** @test */
    public function it_is_not_due_when_checks_not_due(): void
    {
        $schedule = new Periodic(10);

        $check = (new Check(1234))
            ->withSchedule($schedule)
            ->setLastCheck(Carbon::now()->getTimestamp()-9);

        $this->assertFalse($schedule->isDue($check));
    }

    /** @test */
    public function it_is_due_when_checks_last_check_time_is_null(): void
    {
        $schedule = new Periodic(10);

        $check = (new Check(1234))
            ->withSchedule($schedule)
            ->setLastCheck(null);

        $this->assertTrue($schedule->isDue($check));
    }

    /** @test */
    public function it_calculates_accurate_time_due(): void
    {
        TestTime::freeze();

        $interval = 60;

        $schedule = new Periodic($interval);
        $lastCheck = Carbon::now()->getTimestamp()-7;

        $check = (new Check(1234))
            ->withSchedule($schedule)
            ->setLastCheck($lastCheck);

        $this->assertEquals($lastCheck+$interval, $schedule->timeDue($check));

        TestTime::unfreeze();
    }

    /** @test */
    public function it_calculates_accurate_time_due_even_if_past_due(): void
    {
        TestTime::freeze();

        $interval = 60;

        $schedule = new Periodic($interval);
        $lastCheck = Carbon::now()->getTimestamp() - 65;

        $check = (new Check(1234))
            ->withSchedule($schedule)
            ->setLastCheck($lastCheck);

        $this->assertEquals($lastCheck+$interval, $schedule->timeDue($check));

        TestTime::unfreeze();
    }

    /** @test */
    public function it_calculates_time_due_to_be_now_when_check_last_check_time_is_null(): void
    {
        TestTime::freeze();

        $schedule = new Periodic(60);

        $check = (new Check(1234))
            ->withSchedule($schedule)
            ->setLastCheck(null);

        $this->assertEquals(Carbon::now()->getTimestamp(), $schedule->timeDue($check));

        TestTime::unfreeze();
    }
}