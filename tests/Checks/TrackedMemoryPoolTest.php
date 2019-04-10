<?php
namespace SeanKndy\Poller\Tests;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\TrackedMemoryPool;

class TrackedMemoryPoolTest extends TestCase
{
    const NUM_CHECKS = 100;
    protected $checks = [];

    protected function setUp() : void
    {
        $time = time();
        for ($i = 1; $i <= self::NUM_CHECKS; $i++) {
            $check = new Check($i, null, [], 10);
            $check->setLastCheck($time - ($i * 10));
            $this->checks[] = $check;
        }
    }

    public function testPoolDequeueThenRequeueCycle()
    {
        // load up pool with checks
        $pool = new TrackedMemoryPool();
        foreach ($this->checks as $check) {
            $pool->track($check);
        }

        for ($pass = 1; ; $pass++) {
            // checks should come out of queue in reverse order that they were added
            // because every check was added with an increasingly longer last check time
            for ($i = self::NUM_CHECKS; $i >= 1; $i--) {
                $check = $pool->dequeue();
                $this->assertInstanceOf(Check::class, $check, "pass=$pass");
                $this->assertEquals($i, $check->getId(), "pass=$pass");
            }
            $this->assertNull($pool->dequeue(), "pass=$pass");
            $this->assertEquals($pool->countQueued(), 0, "pass=$pass");

            if ($pass == 2) break;

            // now requeue all the checks in random order
            $indexes = range(0, self::NUM_CHECKS-1);
            shuffle($indexes);
            foreach  ($indexes as $i) {
                $pool->enqueue($this->checks[$i]);
            }
            $this->assertEquals($pool->countQueued(), self::NUM_CHECKS);
        }
    }

    public function testPoolWithManyEqualPriorityChecks()
    {
        // test to verify that when queue has several equal-priority checks
        // it still runs correctly

        $numChecks = 100;
        // load up pool with checks
        $pool = new TrackedMemoryPool();
        $time = $lastCheckTime = time()-($numChecks*10);
        for ($i = 1; $i <= $numChecks; $i++) {
            $check = new Check($i, null, [], 10);
            $check->setLastCheck($lastCheckTime);
            $pool->track($check);

            if ($i % 10 == 0 && $i != $numChecks) {
                $lastCheckTime -= 10; // make the last check time 10 seconds fewer
                                      // this should create 10 groups of 10 checks within the queue
            }
        }
        $this->assertEquals($pool->countQueued(), $numChecks);

        // now dequeue each check and ensure that they come out in reverse order that they went in
        for ($i = 1; $i <= $numChecks; $i++) {
            $check = $pool->dequeue();
            $this->assertInstanceOf(Check::class, $check, "i=$i");
            $this->assertEquals($lastCheckTime, $check->getLastCheck(), "i=$i");

            if ($i % 10 == 0) {
                $lastCheckTime += 10;
            }
        }
        $this->assertEquals($pool->countQueued(), 0);
    }

    public function testPoolEnqueue()
    {
        $pool = new TrackedMemoryPool();
        foreach ($this->checks as $check) {
            $pool->track($check, false);
        }
        $this->assertEquals(0, $pool->countQueued());
        $this->assertEquals(count($this->checks), $pool->countTracked());

        foreach ($this->checks as $check) {
            $pool->enqueue($check);
        }
        $this->assertEquals(count($this->checks), $pool->countQueued());
    }

    public function testPoolEnqueueWithoutTrackingResultsInException()
    {
        $this->expectException(\RuntimeException::class);

        $pool = new TrackedMemoryPool();
        $pool->enqueue($this->checks[0]);
    }

    public function testGetCheckReturnsObjectReferenceToOriginal()
    {
        // ensure the pool does not return a cloned/copied Check
        $pool = new TrackedMemoryPool();
        $pool->track($this->checks[0]);

        $check = $pool->getById($this->checks[0]->getId());
        $this->assertSame($this->checks[0], $check);
    }

}
