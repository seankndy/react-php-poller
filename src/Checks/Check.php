<?php

namespace SeanKndy\Poller\Checks;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Handlers\HandlerInterface;
use SeanKndy\Poller\Commands\CommandInterface;
use Carbon\Carbon;

class Check
{
    /**
     * Unique ID for this check
     * @var mixed
     */
    protected $id;

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
    protected ?Result $result;

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
        // its important that if nextCheck is in the past that this is instead set to "now"
        $this->setNextCheck($nextCheck);
        $this->interval = $interval;
        $this->result = $result;
        $this->handlers = $handlers;
        $this->incident = $incident;
        $this->meta = $meta;
    }

    public function isDue(): bool
    {
        return $this->nextCheck && Carbon::now()->getTimestamp() >= $this->nextCheck;
    }

    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    /**
     * @param mixed $val Attribute's value
     */
    public function setAttribute(string $key, $val): self
    {
        $this->attributes[$key] = $val;

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

        return $this;
    }

    /**
     * @param HandlerInterface[] $handlers
     */
    public function setHandlers(array $handlers): self
    {
        $this->handlers = $handlers;

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

    public function getNextCheck(): int
    {
        return $this->nextCheck;
    }

    public function run(): PromiseInterface
    {
        if (! $this->command) {
            throw new \RuntimeException("Cannot execute Check (ID=" .
                $this->getId() . ") because a Command is not defined for it!");
        }

        if ($this->interval > 0) {
            $this->nextCheck += $this->interval;
        }

        return $this->command->run($this);
    }

    public function setNextCheck(int $nextCheckTime = null): self
    {
        if ($nextCheckTime !== null) {
            $now = Carbon::now()->getTimestamp();

            // this is important to override $nextCheckTime to the current time if $nextCheckTime
            // is in the past
            $this->nextCheck = max($now, $nextCheckTime);
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
        $this->lastCheck = $time !== null ? $time : Carbon::now()->getTimestamp();

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

    /**
     * Calculate time to next check, or negative if past due
     */
    public function timeToNextCheck(?int $time = null): int
    {
        $time = $time !== null ? $time : Carbon::now()->getTimestamp();

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
