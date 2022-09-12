<?php

namespace SeanKndy\Poller\Tests;

use Carbon\Carbon;
use React\EventLoop\Loop;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\MemoryQueue;
use SeanKndy\Poller\Checks\QueueInterface;
use SeanKndy\Poller\Commands\CommandInterface;
use SeanKndy\Poller\Server;
use SeanKndy\Poller\Tests\Commands\DummyCommand;
use Spatie\TestTime\TestTime;

class ServerTest extends TestCase
{
    /** @test */
    public function it_executes_due_check(): void
    {
        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $command = $this->createMock(CommandInterface::class);
        $command
            ->expects($this->once())
            ->method('run')
            ->willReturn(\React\Promise\resolve([]));

        $check = new Check(1, $command, [], \time(), 10);

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_does_not_execute_undue_check(): void
    {
        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $command = $this->createMock(CommandInterface::class);
        $command
            ->expects($this->never())
            ->method('run')
            ->willReturn(\React\Promise\resolve([]));

        $check = new Check(1, $command, [], \time()+1000, 10);

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_emits_check_start_event(): void
    {
        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $check = new Check(1, new DummyCommand(), [], \time(), 10);

        $server->on('check.start', $this->expectCallableOnceWith($check));

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_emits_check_finish_event(): void
    {
        TestTime::freeze();
        $currentTimeMs = Carbon::now()->getTimestampMs() * .001;

        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $check = new Check(1, new DummyCommand(), [], \time(), 10);

        $server->on('check.finish', $this->expectCallableOnceWith($check, $currentTimeMs));

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();

        TestTime::unfreeze();
    }

    /** @test */
    public function it_emits_check_error_event_when_check_fails(): void
    {
        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $check = new Check(1, new DummyCommand(true), [], \time(), 10);

        $server->on('check.error', $this->expectCallableOnceWith($check, $this->isInstanceOf(\Exception::class)));

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_emits_error_event_when_dequeue_fails(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->expects($this->once())
            ->method('dequeue')
            ->willReturn(\React\Promise\reject(new \Exception("Uh oh.")));

        $server = new Server(Loop::get(), $queue);

        $server->on('error', $this->expectCallableOnceWith($this->isInstanceOf(\Exception::class)));

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_emits_error_event_when_enqueue_fails(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->expects($this->once())
            ->method('dequeue')
            ->willReturn(\React\Promise\resolve(new Check(1, new DummyCommand(), [], \time(), 10)));
        $queue
            ->expects($this->once())
            ->method('enqueue')
            ->willReturn(\React\Promise\reject(new \Exception("Uh oh.")));

        $server = new Server(Loop::get(), $queue);

        $server->on('error', $this->expectCallableOnceWith($this->isInstanceOf(\Exception::class)));

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_requeues_check_after_it_successfully_runs(): void
    {
        $check = new Check(1, new DummyCommand(false), [], \time(), 10);

        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->expects($this->once())
            ->method('dequeue')
            ->willReturn(\React\Promise\resolve($check));
        $queue
            ->expects($this->once())
            ->method('enqueue')
            ->with($check)
            ->willReturn(\React\Promise\resolve([]));

        $server = new Server(Loop::get(), $queue);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_requeues_check_after_it_unsuccessfully_runs(): void
    {
        $check = new Check(1, new DummyCommand(true), [], \time(), 10);

        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->expects($this->once())
            ->method('dequeue')
            ->willReturn(\React\Promise\resolve($check));
        $queue
            ->expects($this->once())
            ->method('enqueue')
            ->with($check)
            ->willReturn(\React\Promise\resolve([]));

        $server = new Server(Loop::get(), $queue);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_does_not_requeue_check_with_zero_interval(): void
    {
        $check = new Check(1, new DummyCommand(false), [], \time(), 0);

        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->expects($this->once())
            ->method('dequeue')
            ->willReturn(\React\Promise\resolve($check));
        $queue
            ->expects($this->never())
            ->method('enqueue');

        $server = new Server(Loop::get(), $queue);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_flushes_queue_on_stop(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->expects($this->once())
            ->method('flush')
            ->willReturn(\React\Promise\resolve([]));

        $server = new Server(Loop::get(), $queue);

        Loop::futureTick(fn() => $server->stop());
        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }
}