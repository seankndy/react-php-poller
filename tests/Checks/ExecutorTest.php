<?php

namespace SeanKndy\Poller\Tests\Checks;

use Carbon\Carbon;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Results\Handlers\Exceptions\HandlerExecutionException;
use SeanKndy\Poller\Tests\Commands\DummyCommand;
use SeanKndy\Poller\Tests\TestCase;
use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Executor;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Handlers\HandlerInterface;
use SeanKndy\Poller\Results\Result;
use Spatie\TestTime\TestTime;
use function React\Async\await;

class ExecutorTest extends TestCase
{
    /** @test */
    public function it_rejects_with_exception_when_executed_without_command()
    {
        $this->expectException(\RuntimeException::class);

        $check = (new Check(1))
            ->withSchedule(new Periodic(10))
            ->setLastCheckNow();

        await(Executor::execute($check));
    }

    /** @test */
    public function it_sets_last_check_time_when_executed()
    {
        TestTime::freeze();

        $interval = 10;
        $time = Carbon::now()->getTimestamp() - $interval;

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic($interval))
            ->setLastCheck($time);

        $this->assertEquals($time, $check->getLastCheck());

        TestTime::addSeconds($interval);

        await(Executor::execute($check));

        $this->assertEquals(Carbon::now()->getTimestamp(), $check->getLastCheck());

        TestTime::unfreeze();
    }

    /** @test */
    public function it_creates_incident_when_result_goes_from_ok_to_not_ok()
    {
        $check = (new Check(1))
            ->withCommand(new DummyCommand(false, new Result(Result::STATE_CRIT)))
            ->withSchedule(new Periodic(10))
            ->setLastCheckNow();

        $this->assertNull($check->getIncident());

        await(Executor::execute($check));

        $this->assertInstanceOf(Incident::class, ($incident = $check->getIncident()));
        $this->assertEquals(Result::STATE_UNKNOWN, $incident->getFromState());
        $this->assertEquals(Result::STATE_CRIT, $incident->getToState());
    }

    /** @test */
    public function it_does_not_create_new_incident_when_last_result_is_same_as_new_result()
    {
        $incident = Incident::fromResults(new Result(Result::STATE_CRIT), $lastResult = new Result(Result::STATE_CRIT));
        $newResult = new Result(Result::STATE_CRIT);

        $check = (new Check(1))
            ->withCommand(new DummyCommand(false, $newResult))
            ->withSchedule(new Periodic(10))
            ->setResult($lastResult)
            ->setIncident($incident)
            ->setLastCheckNow();

        await(Executor::execute($check));

        $this->assertSame($incident, $check->getIncident());
    }

    /** @test */
    public function it_creates_new_incident_when_result_goes_from_nonok_to_other_nonok()
    {
        $incident = Incident::fromResults(new Result(Result::STATE_CRIT), $lastResult = new Result(Result::STATE_CRIT));
        $newResult = new Result(Result::STATE_WARN);

        $check = (new Check(1))
            ->withCommand(new DummyCommand(false, $newResult))
            ->withSchedule(new Periodic(10))
            ->setResult($lastResult)
            ->setIncident($incident)
            ->setLastCheckNow();

        await(Executor::execute($check));

        $this->assertNotSame($incident, $newIncident = $check->getIncident());
        $this->assertEquals(Result::STATE_CRIT, $newIncident->getFromState());
        $this->assertEquals(Result::STATE_WARN, $newIncident->getToState());
    }

    /** @test */
    public function it_sets_result_in_check_after_successful_run()
    {
        $result = new Result(Result::STATE_WARN);

        $check = (new Check(1))
            ->withCommand(new DummyCommand(false, $result))
            ->withSchedule(new Periodic(10))
            ->setLastCheckNow();

        $this->assertNull($check->getResult());

        await(Executor::execute($check));

        $this->assertSame($result, $check->getResult());
    }

    /** @test */
    public function it_resolves_incident_when_result_goes_from_nonok_to_ok()
    {
        $incident = Incident::fromResults(new Result(Result::STATE_CRIT), $lastResult = new Result(Result::STATE_CRIT));
        $newResult = new Result(Result::STATE_OK);
        $this->assertNull($incident->getResolvedTime());

        $check = (new Check(1))
            ->withCommand(new DummyCommand(false, $newResult))
            ->withSchedule(new Periodic(10))
            ->setResult($lastResult)
            ->setIncident($incident)
            ->setLastCheckNow();

        await(Executor::execute($check));

        $this->assertSame($incident, $check->getIncident());
        $this->assertNotNull($incident->getResolvedTime());
    }

    /** @test */
    public function it_clears_previously_resolved_incident_from_check()
    {
        $incident = Incident::fromResults(new Result(Result::STATE_CRIT), $lastResult = new Result(Result::STATE_CRIT));
        $incident->resolve();

        $check = (new Check(1))
            ->withCommand(new DummyCommand(false, new Result(Result::STATE_OK)))
            ->withSchedule(new Periodic(10))
            ->setResult($lastResult)
            ->setIncident($incident)
            ->setLastCheckNow();

        $this->assertNotNull($check->getIncident());

        await(Executor::execute($check));

        $this->assertNull($check->getIncident());
    }

    /** @test */
    public function it_executes_checks_handlers_after_successful_run()
    {
        $handlerObserver = $this->createMock(HandlerInterface::class);
        $handlerObserver
            ->expects($this->once())
            ->method('mutate')
            ->willReturn(\React\Promise\resolve([]));
        $handlerObserver
            ->expects($this->once())
            ->method('process')
            ->willReturn(\React\Promise\resolve([]));

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handlerObserver])
            ->setLastCheckNow();

        await(Executor::execute($check));
    }

    /** @test */
    public function it_does_not_execute_checks_handlers_after_unsuccessful_run()
    {
        $handlerObserver = $this->createMock(HandlerInterface::class);
        $handlerObserver
            ->expects($this->never())
            ->method('mutate')
            ->willReturn(\React\Promise\resolve([]));
        $handlerObserver
            ->expects($this->never())
            ->method('process')
            ->willReturn(\React\Promise\resolve([]));

        $check = (new Check(1))
            ->withCommand(new DummyCommand(true))
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handlerObserver])
            ->setLastCheckNow();

        try {
            await(Executor::execute($check));
        } catch (\Exception $e) {}
    }

    /** @test */
    public function it_executes_checks_handler_mutations_sequentially()
    {
        $makeHandler = fn($id, $sleep) => new class($id, $sleep) implements HandlerInterface {
            private int $id;

            private float $sleep;

            public function __construct(int $id, float $sleep) {
                $this->id = $id;
                $this->sleep = $sleep;
            }

            public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\Timer\sleep($this->sleep)->then(function() use ($check) {
                    $value = $check->getAttribute('test') ?? '';
                    $check->setAttribute('test', $value . $this->id);
                    return [];
                });
            }

            public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\resolve(new Result());
            }
        };

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([
                $makeHandler(1, 1),
                $makeHandler(2, 0),
                $makeHandler(3, .5),
                $makeHandler(4, 0),
                $makeHandler(5, .7)
            ])
            ->setLastCheckNow();

        await(Executor::execute($check));

        $this->assertEquals('12345', $check->getAttribute('test'));
    }

    /** @test */
    public function it_rejects_with_exception_when_handler_mutation_rejects(): void
    {
        $handler = new class implements HandlerInterface {
            public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\reject(new \Exception("Uh oh."));
            }

            public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\resolve([]);
            }
        };

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handler])
            ->setLastCheckNow();

        $this->expectException(HandlerExecutionException::class);

        try {
            await(Executor::execute($check));
            $this->fail("Exception not thrown!");
        } catch (HandlerExecutionException $e) {
            $this->assertSame($check, $e->getCheck());
            $this->assertSame($handler, $e->getHandler());

            throw $e;
        }
    }

    /** @test */
    public function it_rejects_with_exception_when_handler_mutation_throws(): void
    {
        $handler = new class implements HandlerInterface {
            public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                throw new \Exception("Uh oh.");
            }

            public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\resolve([]);
            }
        };

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handler])
            ->setLastCheckNow();

        $this->expectException(HandlerExecutionException::class);

        try {
            await(Executor::execute($check));
            $this->fail("Exception not thrown!");
        } catch (HandlerExecutionException $e) {
            $this->assertSame($check, $e->getCheck());
            $this->assertSame($handler, $e->getHandler());

            throw $e;
        }
    }

    /** @test */
    public function it_rejects_with_exception_when_handler_process_rejects(): void
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
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handler])
            ->setLastCheckNow();

        $this->expectException(HandlerExecutionException::class);

        try {
            await(Executor::execute($check));
            $this->fail("Exception not thrown!");
        } catch (HandlerExecutionException $e) {
            $this->assertSame($check, $e->getCheck());
            $this->assertSame($handler, $e->getHandler());

            throw $e;
        }
    }

    /** @test */
    public function it_rejects_with_exception_when_handler_process_throws(): void
    {
        $handler = new class implements HandlerInterface {
            public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                return \React\Promise\resolve([]);
            }

            public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface {
                throw new \Exception("Uh oh.");
            }
        };

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handler])
            ->setLastCheckNow();

        $this->expectException(HandlerExecutionException::class);

        try {
            await(Executor::execute($check));
            $this->fail("Exception not thrown!");
        } catch (HandlerExecutionException $e) {
            $this->assertSame($check, $e->getCheck());
            $this->assertSame($handler, $e->getHandler());

            throw $e;
        }
    }

    /** @test */
    public function it_stops_processing_handlers_after_mutation_rejects(): void
    {
        $handler1 = $this->createMock(HandlerInterface::class);
        $handler1
            ->expects($this->once())
            ->method('mutate')
            ->willReturn(\React\Promise\reject(new \RuntimeException("Uh oh.")));
        $handler1
            ->expects($this->never())
            ->method('process');

        $handler2 = $this->createMock(HandlerInterface::class);
        $handler2
            ->expects($this->never())
            ->method('mutate');
        $handler2
            ->expects($this->never())
            ->method('process');

        $check = (new Check(1))
            ->withCommand(new DummyCommand())
            ->withSchedule(new Periodic(10))
            ->withHandlers([$handler1, $handler2])
            ->setLastCheckNow();

        $this->expectException(HandlerExecutionException::class);

        await(Executor::execute($check));
    }

}