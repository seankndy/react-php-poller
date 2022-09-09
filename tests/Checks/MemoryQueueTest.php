<?php

namespace SeanKndy\Poller\Tests\Checks;

use Carbon\Carbon;
use SeanKndy\Poller\Tests\TestCase;
use Spatie\TestTime\TestTime;
use function React\Async\await;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\MemoryQueue;

class MemoryQueueTest extends TestCase
{
    const NUM_CHECKS = 10;

    /** @test */
    public function it_dequeues_checks_most_overdue_first()
    {
        $queue = new MemoryQueue();

        TestTime::freeze();
        TestTime::addHour(1);
        $time = Carbon::now()->getTimestamp();
        TestTime::subHour(1);

        // create array of Checks, each Check having a next check time earlier than the last
        $checks = [];
        for ($i = 0; $i < self::NUM_CHECKS; $i++) {
            $checks[] = new Check(
                $i+1,
                null,
                [],
                $time - $i,
                10
            );
        }
        TestTime::addHour(1);

        // randomize checks so they're queued in random order
        shuffle($checks);

        // load up queue with checks
        foreach ($checks as $check) {
            $queue->enqueue($check);
        }

        // checks should come out of queue based on earliest next check time first
        for ($i = self::NUM_CHECKS; $i >= 1; $i--) {
            $check = await($queue->dequeue());

            $this->assertEquals($i, $check->getId());
        }

        TestTime::unfreeze();
    }

    /** @test */
    public function it_dequeues_nothing_when_queue_empty()
    {
        $queue = new MemoryQueue();

        $this->assertNull(await($queue->dequeue()));
    }

    /** @test */
    public function it_dequeues_nothing_when_no_checks_in_queue_are_due()
    {
        $queue = new MemoryQueue();

        $queue->enqueue(new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp()+10, // in future
            60
        ));

        $this->assertNull(await($queue->dequeue()));
    }

    /** @test */
    public function it_dequeues_check_when_its_due()
    {
        $queue = new MemoryQueue();

        $queue->enqueue(new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp()-10, // in past
            60
        ));

        $this->assertEquals(1, await($queue->dequeue())->getId());
    }

    /** @test */
    public function it_dequeues_check_only_once()
    {
        $queue = new MemoryQueue();

        $queue->enqueue(new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp()-10, // in past
            60
        ));

        $this->assertEquals(1, await($queue->dequeue())->getId());
        $this->assertNull(await($queue->dequeue()));
    }

    /** @test */
    public function it_dequeues_only_checks_that_are_due()
    {
        $queue = new MemoryQueue();

        $queue->enqueue(new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp()-10, // in past, due
            60
        ));
        $queue->enqueue(new Check(
            2,
            null,
            [],
            Carbon::now()->getTimestamp()+10, // in future, not due
            60
        ));

        $this->assertEquals(1, await($queue->dequeue())->getId());
        $this->assertNull(await($queue->dequeue()));
    }

    /** @test */
    public function it_counts_populated_queue_accurately()
    {
        $queue = new MemoryQueue();

        $queue->enqueue(new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp()-10, // in past
            60
        ));
        $queue->enqueue(new Check(
            2,
            null,
            [],
            Carbon::now()->getTimestamp()+10, // in future
            60
        ));

        $this->assertEquals(2, await($queue->countQueued()));
    }

    /** @test */
    public function it_counts_empty_queue_accurately()
    {
        $queue = new MemoryQueue();

        $this->assertEquals(0, await($queue->countQueued()));
    }

    /** @test */
    public function it_dequeues_original_object_not_a_clone()
    {
        // ensure the pool does not return a cloned/copied Check
        $queue = new MemoryQueue();
        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp()-10, // in past
            60
        );
        $queue->enqueue($check);

        $this->assertSame($check, await($queue->dequeue()));
    }
}
