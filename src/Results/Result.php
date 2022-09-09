<?php

namespace SeanKndy\Poller\Results;

use Ramsey\Uuid\Uuid;
use Carbon\Carbon;
use SeanKndy\Poller\Checks\Check;

/**
 * Basic data structure for storing Result from Check Command
 */
class Result
{
    const STATE_OK = 0;
    const STATE_WARN = 1;
    const STATE_CRIT = 2;
    const STATE_UNKNOWN = 3;

    protected string $id;

    protected int $state;

    protected ?string $stateReason;

    protected MetricSet $metrics;

    protected int $time;

    public function __construct(
        int $state = self::STATE_UNKNOWN,
        ?string $stateReason = null,
        array $metrics = [],
        ?int $time = null
    ) {
        $this->id = Uuid::uuid4()->toString();
        $this->state = $state;
        $this->stateReason = $stateReason;
        $this->setMetrics($metrics);
        $this->time = $time ?: Carbon::now()->getTimestamp();
    }

    public function setState(int $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function setStateReason(?string $reason): self
    {
        $this->stateReason = $reason;

        return $this;
    }

    public function getStateReason(): ?string
    {
        return $this->stateReason;
    }

    /**
     * @param Metric[] $metrics
     */
    public function setMetrics(array $metrics): self
    {
        $this->metrics = new MetricSet();

        foreach ($metrics as $metric) {
            $this->metrics->attach($metric);
        }

        return $this;
    }

    public function addMetric(Metric $metric): self
    {
        $this->metrics->attach($metric);

        return $this;
    }

    public function getState(): int
    {
        return $this->state;
    }

    public function getTime(): int
    {
        return $this->time;
    }

    public function setTime(int $time): self
    {
        $this->time = $time;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getStateString(): int
    {
        return self::stateIntToString($this->state);
    }

    /**
     * @return Metric[]
     */
    public function getMetrics(): array
    {
        return \iterator_to_array($this->metrics);
    }

    /**
     * Return integer const value for string state name
     */
    public static function stateStringToInt(string $state = null): int
    {
        $m = [
            'UNKNOWN' => Result::STATE_UNKNOWN,
            'OK' => Result::STATE_OK,
            'OKAY' => Result::STATE_OK,
            'NORMAL' => Result::STATE_OK,
            'WARN' => Result::STATE_WARN,
            'WARNING' => Result::STATE_WARN,
            'CRIT' => Result::STATE_CRIT,
            'CRITICAL' => Result::STATE_CRIT
        ];
        $state = strtoupper(trim($state));
        if (!$state || !isset($m[$state])) {
            return Result::STATE_UNKNOWN;
        }
        return $m[$state];
    }

    public static function stateIntToString(int $state): string
    {
        $states = [
            self::STATE_OK => 'OK',
            self::STATE_CRIT => 'CRIT',
            self::STATE_WARN => 'WARN',
            self::STATE_UNKNOWN => 'UNKNOWN'
        ];

        return $states[$state];
    }

    /**
     * Determine if Result is in an OK state
     */
    public function ok(): bool
    {
        return ($this->getState() === self::STATE_OK);
    }

    /**
     * Does $this Result justify the creation of a new Incident for Check $check?
     */
    public function justifiesNewIncidentForCheck(Check $check): bool
    {
        // if incident suppression is on, never allow new incident
        if ($check->areIncidentsSuppressed()) {
            return false;
        }

        $lastResult = $check->getResult();
        $lastIncident = $check->getIncident();

        // if current result is OK, no incident
        if ($this->ok()) {
            return false;
        }

        // current result NOT OK and last incident exists
        if ($lastIncident) {
            // last incident to-state different from $this' state
            return $lastIncident->getToState() !== $this->getState();
        }

        // current result NOT OK and NO last incident exists
        // and last result exists
        if ($lastResult) {
            // last result state different from new state
            return $lastResult->getState() !== $this->getState();
        }

        // not ok, no last incident, no last result
        return true;
    }

    /**
     * When cloned, clone metrics as well
     */
    public function __clone()
    {
        $metrics = [];
        foreach ($this->metrics as $metric) {
            $metrics[] = clone $metric;
        }
        $this->setMetrics($metrics);
    }
}
