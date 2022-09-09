<?php

namespace SeanKndy\Poller\Checks;

use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Handlers\HandlerInterface;
use SeanKndy\Poller\Commands\CommandInterface;

class Check
{
    const STATE_NEW = 0;
    const STATE_IDLE = 1;
    const STATE_EXECUTING = 2;
    const STATE_ERRORED = 255;

    /**
     * Unique ID for this check
     * @var mixed
     */
    protected $id;

    protected int $state = self::STATE_IDLE;

    /**
     * Interval in seconds the Check is due to run.
     * A value <=0 means the Check should not be re-enqueued()
     */
    protected int $interval;

    protected bool $incidentsSuppressed = false;

    protected ?CommandInterface $command;

    protected array $attributes = [];

    protected ?int $lastCheck = null;

    protected ?int $nextCheck = null;

    /**
     * @var HandlerInterface[]
     */
    protected array $handlers = [];

    /**
     * Current Result for the check, may be null
     */
    protected ?Result $result = null;

    protected float $lastStateDuration = 0.0;

    protected float $stateChangeTime = 0.0;

    protected int $lastState = self::STATE_NEW;

    /**
     * Current Incident for Check.  As long as the incident is unresolved, this
     * will be that incident.  After it's resolved, it will be nulled.
     */
    protected ?Incident $incident;

    /**
     * Any misc meta data to attach to the Check.
     * @var mixed|null
     */
    protected $meta;

    /**
     * Time this Check object was 'changed' such as
     * updated attributes, handlers or interval
     * Updated state, result and lastCheck does not count as 'changed'
     */
    protected int $lastChanged;

    public function __construct(
        $id,
        ?CommandInterface $command,
        array $attributes,
        int $nextCheck,
        int $interval,
        Result $result = null,
        array $handlers = [],
        Incident $incident = null,
        $meta = null
    ) {
        $this->id = $id;
        $this->command = $command;
        $this->attributes = $attributes;
        $this->setState(self::STATE_IDLE);
        // its important that if nextCheck is in the past that this is instead set to "now"
        $this->setNextCheck($nextCheck);
        $this->interval = $interval;
        $this->result = $result;
        $this->handlers = $handlers;
        $this->lastChanged = \time();
        $this->incident = $incident;
        $this->meta = $meta;
    }

    public function setState(int $state): self
    {
        if ($this->state != $state) {
            if ($this->stateChangeTime) {
                $this->lastStateDuration = \microtime(true) - $this->stateChangeTime;
            }
            $this->stateChangeTime = \microtime(true);
            $this->lastState = $this->state;
        }
        $this->state = $state;

        return $this;
    }

    public function isDue(?int $time = null): bool
    {
        if ($time == null) $time = \time();

        return ($this->state != self::STATE_EXECUTING
            && $this->nextCheck && $time >= $this->nextCheck);

        /*
        return ($this->state != self::STATE_EXECUTING && (!$this->lastCheck
            || $time >= $this->timeOfNextCheck()));
        */
    }

    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;
        $this->lastChanged = \time();

        return $this;
    }

    /**
     * @param mixed $val Attribute's value
     */
    public function setAttribute(string $key, $val): self
    {
        $this->attributes[$key] = $val;
        $this->lastChanged = \time();

        return $this;
    }

    /**
     * @return mixed|null
     */
    public function getAttribute(string $key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getCommand(): ?CommandInterface
    {
        return $this->command;
    }

    public function setCommand(CommandInterface $cmd): self
    {
        $this->command = $cmd;
        $this->lastChanged = \time();

        return $this;
    }

    /**
     * @param HandlerInterface[] $handlers
     */
    public function setHandlers(array $handlers): self
    {
        $this->handlers = $handlers;
        $this->lastChanged = \time();

        return $this;
    }

    public function addHandler(HandlerInterface $handler): self
    {
        $this->handlers[] = $handler;

        return $this;
    }

    /**
     * @return HandlerInterface[]
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    public function getState(): int
    {
        return $this->state;
    }

    public function getIncident(): ?Incident
    {
        return $this->incident;
    }

    public function setIncident(?Incident $incident = null): self
    {
        $this->incident = $incident;

        return $this;
    }

    public function setIncidentsSuppressed(bool $flag): self
    {
        $this->incidentsSuppressed = $flag;

        return $this;
    }

    public function areIncidentsSuppressed(): bool
    {
        return $this->incidentsSuppressed;
    }

    public function getInterval(): int
    {
        return $this->interval;
    }

    public function setResult(?Result $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function getResult(): ?Result
    {
        return $this->result;
    }

    public function getLastStateDuration(): float
    {
        return $this->lastStateDuration;
    }

    public function getNextCheck(): int
    {
        return $this->nextCheck;
    }

    public function setNextCheck(int $neckCheckTime = null): self
    {
        if ($neckCheckTime !== null) {
            // this is important to override $time to the current time if $time
            // is already in the past
            $this->nextCheck = \time() > $neckCheckTime ? \time() : $neckCheckTime;
        } else if ($this->interval > 0) {
            $this->nextCheck += $this->interval;
        } else {
            $this->nextCheck = null;
        }

        return $this;
    }

    public function getLastCheck(): ?int
    {
        return $this->lastCheck;
    }

    public function setLastCheck(?int $time = null): self
    {
        $this->lastCheck = $time !== null ? $time : \time();

        return $this;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed|null $meta
     */
    public function setMeta($meta): self
    {
        $this->meta = $meta;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getMeta()
    {
        return $this->meta;
    }

    public function getLastChanged(): int
    {
        return $this->lastChanged;
    }

    /**
     * Is this check up to date according to timestamp $time
     *
     * @param string|int $time
     */
    public function isUpToDate($time): bool
    {
        if (\is_string($time) && $time && \strtolower($time) != 'null') {
            $time = \strtotime($time);
        } else if (!is_int($time)) {
            return true;
        }

        return $this->getLastChanged() >= $time;
    }

    /**
     * Calculate time to next check, or negative if past due
     */
    public function timeToNextCheck(?int $time = null): int
    {
        $time = $time !== null ? $time : \time();

        return ($this->getNextCheck() - $time);
    }

    /**
     * @deprecated Use getNextCheck() instead.
     */
    public function timeOfNextCheck(): int
    {
        return $this->getNextCheck();
    }

    /**
     * Determine if new Incident is warranted based on the new Result.
     */
    public function isNewIncident(Result $currentResult): bool
    {
        // if incident suppression is on, never allow new incident
        if ($this->areIncidentsSuppressed()) {
            return false;
        }

        $lastResult = $this->getResult();
        $lastIncident = $this->getIncident();

        // if current result is OK, no incident
        if (Result::isOK($currentResult)) {
            //$resolveLastIncident();
            return false;
        }

        // current result NOT OK and last incident exists
        if ($lastIncident) {
            // last incident to-state different from current result state
            if ($lastIncident->getToState() != $currentResult->getState()) {
                return true;
                //$resolveLastIncident();
                //return $makeNewIncident();
            }
            return false;
        }

        // current result NOT OK and NO last incident exists
        // and last result exists
        if ($lastResult) {
            // last result state different from new state
            if ($lastResult->getState() != $currentResult->getState()) {
                return true;
                //return $makeNewIncident();
            }
            return false;
        }

        // not ok, no last incident, no last result
        return true;
    }

    /**
     * Clone handler
     * When this object cloned, we want the result to be cloned with it.
     *
     */
    public function __clone()
    {
        if ($this->result)
            $this->result = clone $this->result;
        if ($this->incident)
            $this->incident = clone $this->incident;
    }
}
