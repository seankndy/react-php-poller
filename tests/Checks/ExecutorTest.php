<?php

namespace SeanKndy\Poller\Tests\Checks;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Executor;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Commands\CommandInterface;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Tests\TestCase;
use function React\Async\await;

class ExecutorTest extends TestCase
{
    /** @test */
    public function it_throws_exception_if_command_is_not_valid()
    {
        $this->expectException(\RuntimeException::class);

        $check = new Check(1, null, [], \time(), 10);
        await((new Executor())->execute($check));
    }

    /** @test */
    public function it_increments_next_check_time_by_interval_time()
    {
        $time = \time();
        $interval = 10;
        $check = new Check(1, new DummyCommand(), [], $time, $interval);

        await((new Executor())->execute($check));

        $this->assertEquals($time + $interval, $check->getNextCheck());
    }

    /** @test */
    public function it_updates_checks_last_check_time_after_successful_run()
    {
        $interval = 10;
        $time = \time() - $interval;
        $check = new Check(1, new DummyCommand(), [], $time + $interval, $interval);
        $check->setLastCheck($time);

        await((new Executor())->execute($check));

        $this->assertNotEquals($time, $check->getLastCheck());
    }

    /** @test */
    public function it_updates_checks_last_check_time_after_unsuccessful_run()
    {
        $interval = 10;
        $time = \time() - $interval;
        $check = new Check(1, new DummyCommand(true), [], $time + $interval, $interval);
        $check->setLastCheck($time);

        try {
            await((new Executor())->execute($check));
        } catch (\Exception $e) {}

        $this->assertNotEquals($time, $check->getLastCheck());
    }

    /** @test */
    public function it_creates_incident_when_result_goes_from_ok_to_not_ok()
    {
        $check = new Check(1, new DummyCommand(false, new Result(Result::STATE_CRIT)), [], \time(), 10);
        $this->assertNull($check->getIncident());

        await((new Executor())->execute($check));

        $this->assertInstanceOf(Incident::class, ($incident = $check->getIncident()));
        $this->assertEquals(Result::STATE_UNKNOWN, $incident->getFromState());
        $this->assertEquals(Result::STATE_CRIT, $incident->getToState());
    }

    /** @test */
    public function it_does_not_create_new_incident_when_last_result_is_same_as_new_result()
    {
        $incident = Incident::fromResults(new Result(Result::STATE_CRIT), $lastResult = new Result(Result::STATE_CRIT));
        $newResult = new Result(Result::STATE_CRIT);

        $check = new Check(1, new DummyCommand(false, $newResult), [], \time(), 10, $lastResult, [], $incident);

        await((new Executor())->execute($check));

        $this->assertSame($incident, $check->getIncident());
    }

    /** @test */
    public function it_creates_new_incident_when_result_goes_from_nonok_to_other_nonok()
    {
        $incident = Incident::fromResults(new Result(Result::STATE_CRIT), $lastResult = new Result(Result::STATE_CRIT));
        $newResult = new Result(Result::STATE_WARN);

        $check = new Check(1, new DummyCommand(false, $newResult), [], \time(), 10, $lastResult, [], $incident);

        await((new Executor())->execute($check));

        $this->assertNotSame($incident, $newIncident = $check->getIncident());
        $this->assertEquals(Result::STATE_CRIT, $newIncident->getFromState());
        $this->assertEquals(Result::STATE_WARN, $newIncident->getToState());
    }

    /** @test */
    public function it_resolves_incident_when_result_goes_from_nonok_to_ok()
    {
        $incident = Incident::fromResults(new Result(Result::STATE_CRIT), $lastResult = new Result(Result::STATE_CRIT));
        $newResult = new Result(Result::STATE_OK);
        $this->assertNull($incident->getResolvedTime());

        $check = new Check(1, new DummyCommand(false, $newResult), [], \time(), 10, $lastResult, [], $incident);

        await((new Executor())->execute($check));

        $this->assertSame($incident, $check->getIncident());
        $this->assertNotNull($incident->getResolvedTime());
    }

    /** @test */
    public function it_clears_previously_resolved_incident_from_check()
    {
        $incident = Incident::fromResults(new Result(Result::STATE_CRIT), $lastResult = new Result(Result::STATE_CRIT));
        $incident->resolve();

        $check = new Check(1, new DummyCommand(false, new Result(Result::STATE_OK)), [], \time(), 10, $lastResult, [], $incident);
        $this->assertNotNull($check->getIncident());

        await((new Executor())->execute($check));

        $this->assertNull($check->getIncident());
    }

    /** @test */
    public function it_executes_checks_handlers_after_successful_run()
    {
        //
    }

    /** @test */
    public function it_does_not_execute_checks_handlers_after_unsuccessful_run()
    {
        //
    }
}

class DummyCommand implements CommandInterface
{
    private bool $failRun;

    private Result $result;

    public function __construct($failRun = false, ?Result $result = null)
    {
        $this->failRun = $failRun;
        if (!$result) {
            $result = new Result();
        }
        $this->result = $result;
    }

    public function run(Check $check): PromiseInterface
    {
        return $this->failRun
            ? \React\Promise\reject(new \Exception("Oops."))
            : \React\Promise\resolve($this->result);
    }

    public function getProducableMetrics(array $attributes): array
    {
        return [];
    }
}