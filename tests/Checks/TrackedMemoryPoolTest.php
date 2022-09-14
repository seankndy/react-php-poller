<?php
namespace SeanKndy\Poller\Tests\Checks;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Checks\TrackedMemoryPool;
use SeanKndy\Poller\Tests\TestCase;
use function React\Async\await;

class TrackedMemoryPoolTest extends TestCase
{
    const NUM_CHECKS = 100;

    /** @test */
    public function it_enqueues_checks()
    {
        $pool = new TrackedMemoryPool();

        $time = \time();
        $checks = [];
        for ($i = 1; $i <= self::NUM_CHECKS; $i++) {
            $checks[] = (new Check($i))
                ->withSchedule(new Periodic(10))
                ->setLastCheck($time - ($i * 10));
        }

        foreach ($checks as $check) {
            $pool->track($check, false);
        }
        $this->assertEquals(0, await($pool->countQueued()));
        $this->assertEquals(count($checks), $pool->countTracked());

        foreach ($checks as $check) {
            $pool->enqueue($check);
        }
        $this->assertEquals(count($checks), await($pool->countQueued()));
    }

    /** @test */
    public function it_throws_exception_if_enqueue_called_prior_to_tracking()
    {
        $this->expectException(\RuntimeException::class);

        $pool = new TrackedMemoryPool();
        await($pool->enqueue((new Check(1))->withSchedule(new Periodic(10))->setLastCheckNow()));
    }

}
