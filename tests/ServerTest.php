<?php

namespace SeanKndy\Poller\Tests;

use Carbon\Carbon;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Checks\MemoryQueue;
use SeanKndy\Poller\Checks\QueueInterface;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Commands\CommandInterface;
use SeanKndy\Poller\Results\Handlers\Exceptions\HandlerExecutionException;
use SeanKndy\Poller\Results\Handlers\HandlerInterface;
use SeanKndy\Poller\Results\Result;
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

        $check = (new Check(1))
            ->withCommand($command)
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

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

        $check = (new Check(1))
            ->withCommand($command)
            ->withSchedule(new Periodic(10))
            ->setLastCheckNow();

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_emits_check_start_event(): void
    {
        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

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

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

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

        $check = (new Check(1))
            ->withCommand(new DummyCommand(true))
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

        $server->on('check.error', $this->expectCallableOnceWith($check, $this->isInstanceOf(\Exception::class)));

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_emits_check_finish_event_when_check_execution_rejects_with_handler_related_exception(): void
    {
        $handler = new class implements HandlerInterface {
            public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\reject(new \Exception("Uh oh."));
            }

            public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\resolve([]);
            }
        };

        TestTime::freeze();
        $currentTimeMs = Carbon::now()->getTimestampMs() * .001;

        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handler])
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

        $server->on('check.finish', $this->expectCallableOnceWith($check, $currentTimeMs));

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();

        TestTime::unfreeze();
    }

    /** @test */
    public function it_emits_check_error_event_when_check_execution_rejects_with_handler_failure(): void
    {
        $handler = new class implements HandlerInterface {
            public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\reject(new \Exception("Uh oh."));
            }

            public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\resolve([]);
            }
        };

        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handler])
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

        $server->on('check.error', $this->expectCallableOnceWith($check, $this->isInstanceOf(HandlerExecutionException::class)));

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();
    }

    /** @test */
    public function it_emits_check_warn_event_when_check_ran_excessively_late(): void
    {
        TestTime::freeze();

        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(60))
            ->setLastCheck(Carbon::now()->getTimestamp()-120);

        $server->on('check.warn', $this->expectCallableOnceWith($check, "Check is 60 seconds late to start."));

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();

        TestTime::unfreeze();
    }

    /** @test */
    public function it_does_not_emit_check_warn_event_when_check_runs_reasonably_on_time(): void
    {
        TestTime::freeze();

        $server = new Server(Loop::get(), $queue = new MemoryQueue());

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(60))
            ->setLastCheck(Carbon::now()->getTimestamp()-70);

        $server->on('check.warn', $this->expectCallableNever());

        $queue->enqueue($check);

        Loop::futureTick(fn() => Loop::stop());
        Loop::run();

        TestTime::unfreeze();
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
        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

        $queue = $this->createMock(QueueInterface::class);
        $queue
            ->expects($this->once())
            ->method('dequeue')
            ->willReturn(\React\Promise\resolve($check));
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
        $check = (new Check(1))
            ->withCommand(new DummyCommand(false))
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

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
        $check = (new Check(1))
            ->withCommand(new DummyCommand(true))
            ->withSchedule(new Periodic(10))
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

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
    public function it_requeues_check_after_it_rejects_with_handler_failure(): void
    {
        $handler = new class implements HandlerInterface {
            public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\resolve([]);
            }

            public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\reject(new \Exception("Uh oh."));
            }
        };

        $check = (new Check(1))
            ->withCommand(new DummyCommand(true))
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handler])
            ->setLastCheck(Carbon::now()->getTimestamp()-10);

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
    public function it_does_not_requeue_check_with_no_schedule(): void
    {
        $check = (new Check(1))
            ->withCommand(new DummyCommand(false))
            ->withSchedule(null)
            ->setLastCheck(Carbon::now()->getTimestamp()-60);

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