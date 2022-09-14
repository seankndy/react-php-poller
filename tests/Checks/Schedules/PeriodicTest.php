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
        $check = new Check(1234, null, [], Carbon::now()->getTimestamp()-11, $schedule);

        $this->assertTrue($schedule->isDue($check));
    }

    /** @test */
    public function it_is_due_when_check_is_exactly_due(): void
    {
        $schedule = new Periodic(10);
        $check = new Check(1234, null, [], Carbon::now()->getTimestamp()-10, $schedule);

        $this->assertTrue($schedule->isDue($check));
    }

    /** @test */
    public function it_is_not_due_when_checks_not_due(): void
    {
        $schedule = new Periodic(10);
        $check = new Check(1234, null, [], Carbon::now()->getTimestamp()-9, $schedule);

        $this->assertFalse($schedule->isDue($check));
    }

    /** @test */
    public function it_is_due_when_checks_last_check_time_is_null(): void
    {
        $schedule = new Periodic(10);
        $check = new Check(1234, null, [], null, $schedule);

        $this->assertTrue($schedule->isDue($check));
    }

    /** @test */
    public function it_calculates_seconds_until_due(): void
    {
        TestTime::freeze();

        $schedule = new Periodic(60);
        $check = new Check(1234, null, [], Carbon::now()->getTimestamp()-5, $schedule);

        $this->assertEquals(55, $schedule->secondsUntilDue($check));

        TestTime::unfreeze();
    }

    /** @test */
    public function it_calculates_negative_seconds_until_due_if_past_due(): void
    {
        TestTime::freeze();

        $schedule = new Periodic(60);
        $check = new Check(1234, null, [], Carbon::now()->getTimestamp()-65, $schedule);

        $this->assertEquals(-5, $schedule->secondsUntilDue($check));

        TestTime::unfreeze();
    }

    /** @test */
    public function it_calculates_zero_seconds_until_due_for_check_with_null_last_check_time(): void
    {
        TestTime::freeze();

        $schedule = new Periodic(60);
        $check = new Check(1234, null, [], null, $schedule);

        $this->assertEquals(0, $schedule->secondsUntilDue($check));

        TestTime::unfreeze();
    }
}