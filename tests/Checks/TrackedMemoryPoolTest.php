<?php
namespace SeanKndy\Poller\Tests\Checks;

use SeanKndy\Poller\Checks\Check;
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
            $check = new Check($i, null, [], \time(), 10);
            $check->setLastCheck($time - ($i * 10));

            $checks[] = $check;
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
        await($pool->enqueue(new Check(1, null, [], \time(), 10)));
    }

}
