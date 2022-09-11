<?php

namespace SeanKndy\Poller\Tests\Results;

use Carbon\Carbon;
use Ramsey\Uuid\Uuid;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Tests\TestCase;

class ResultTest extends TestCase
{
    /** @test */
    public function it_does_not_justify_new_incident_when_check_result_ok()
    {
        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_CRIT));

        $this->assertFalse((new Result(Result::STATE_OK))->justifiesNewIncidentForCheck($check));
    }

    /** @test */
    public function it_justifies_new_incident_when_check_result_state_goes_from_ok_to_crit()
    {
        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_OK));

        $this->assertTrue((new Result(Result::STATE_CRIT))->justifiesNewIncidentForCheck($check));
    }

    /** @test */
    public function it_justifies_new_incident_when_check_result_state_goes_from_ok_to_warn()
    {
        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_OK));

        $this->assertTrue((new Result(Result::STATE_WARN))->justifiesNewIncidentForCheck($check));
    }

    /** @test */
    public function it_justifies_new_incident_when_check_result_state_goes_from_ok_to_unknown()
    {
        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_OK));

        $this->assertTrue((new Result(Result::STATE_UNKNOWN))->justifiesNewIncidentForCheck($check));
    }

    /** @test */
    public function it_justifies_new_incident_when_check_last_incident_tostate_different_from_new_result_state()
    {
        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            10,
            new Result(Result::STATE_WARN),
            [],
            new Incident(1, Result::STATE_CRIT, Result::STATE_WARN)
        );

        $this->assertTrue((new Result(Result::STATE_CRIT))->justifiesNewIncidentForCheck($check));
    }

    /** @test */
    public function it_does_not_justify_new_incident_when_check_last_incident_tostate_same_as_new_result_state()
    {
        $check = new Check(
            1,
            null,
            [],
            Carbon::now()->getTimestamp(),
            10,
            new Result(Result::STATE_WARN),
            [],
            new Incident(1, Result::STATE_CRIT, Result::STATE_WARN)
        );

        $this->assertFalse((new Result(Result::STATE_WARN))->justifiesNewIncidentForCheck($check));
    }

    /** @test */
    public function it_justifies_new_incident_when_check_result_state_goes_from_not_ok_to_other_not_ok()
    {
        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_CRIT));
        $this->assertTrue((new Result(Result::STATE_WARN))->justifiesNewIncidentForCheck($check));

        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_CRIT));
        $this->assertTrue((new Result(Result::STATE_UNKNOWN))->justifiesNewIncidentForCheck($check));

        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_WARN));
        $this->assertTrue((new Result(Result::STATE_CRIT))->justifiesNewIncidentForCheck($check));

        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_WARN));
        $this->assertTrue((new Result(Result::STATE_UNKNOWN))->justifiesNewIncidentForCheck($check));

        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_UNKNOWN));
        $this->assertTrue((new Result(Result::STATE_CRIT))->justifiesNewIncidentForCheck($check));

        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_UNKNOWN));
        $this->assertTrue((new Result(Result::STATE_WARN))->justifiesNewIncidentForCheck($check));
    }

    /** @test */
    public function it_does_not_justify_new_incident_when_check_incident_suppression_enabled()
    {
        $check = new Check(1, null, [], Carbon::now()->getTimestamp(), 10, new Result(Result::STATE_OK));
        $check->setIncidentsSuppressed(true);

        $this->assertFalse((new Result(Result::STATE_CRIT))->justifiesNewIncidentForCheck($check));
    }

    /**
     * @test
     * @dataProvider provideStateStringToIntData
     */
    public function it_converts_state_string_to_int(int $expectedResult, ?string $input)
    {
        $this->assertEquals($expectedResult, Result::stateStringToInt($input));
    }

    public function provideStateStringToIntData(): array
    {
        return [
            [Result::STATE_UNKNOWN, null],
            [Result::STATE_UNKNOWN, 'Bad state string'],
            [Result::STATE_UNKNOWN, 'UNKNOWN'],
            [Result::STATE_OK, 'OK'],
            [Result::STATE_OK, 'OKAY'],
            [Result::STATE_OK, 'NORMAL'],
            [Result::STATE_WARN, 'WARN'],
            [Result::STATE_WARN, 'WARNING'],
            [Result::STATE_CRIT, 'CRIT'],
            [Result::STATE_CRIT, 'CRITICAL'],
        ];
    }

    /**
     * @test
     * @dataProvider provideStateIntToStringData
     */
    public function it_converts_state_int_to_string(string $expectedResult, int $input)
    {
        $this->assertEquals($expectedResult, Result::stateIntToString($input));
    }

    public function provideStateIntToStringData(): array
    {
        return [
            ['UNKNOWN', Result::STATE_UNKNOWN],
            ['OK', Result::STATE_OK],
            ['WARN', Result::STATE_WARN],
            ['CRIT', Result::STATE_CRIT],
        ];
    }

    /** @test */
    public function when_state_is_ok_then_ok_is_true()
    {
        $this->assertTrue((new Result(Result::STATE_OK))->ok());
    }

    /** @test */
    public function when_state_is_not_ok_then_ok_is_false()
    {
        $this->assertFalse((new Result(Result::STATE_UNKNOWN))->ok());
        $this->assertFalse((new Result(Result::STATE_CRIT))->ok());
        $this->assertFalse((new Result(Result::STATE_WARN))->ok());
    }

    /** @test */
    public function it_generates_uuid_for_id_on_instantiation()
    {
        $result = new Result();

        $this->assertTrue(Uuid::isValid($result->getId()));
    }

    /** @test */
    public function it_does_not_instantiate_with_invalid_state(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Result(1234);
    }

    /** @test */
    public function it_does_not_allow_setting_to_an_invalid_state(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Result())->setState(4321);
    }
}