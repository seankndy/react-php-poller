<?php

namespace SeanKndy\Poller\Checks;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Schedules\ScheduleInterface;
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
     * Scheduling of check runs, or null for Checks that should not be re-enqueued().
     */
    protected ?ScheduleInterface $schedule;

    protected ?CommandInterface $command;

    protected bool $incidentsSuppressed = false;

    protected array $attributes = [];

    protected ?int $lastCheck = null;

    /**
     * @var HandlerInterface[]
     */
    protected array $handlers = [];

    /**
     * Current Result for the check, may be null
     */
    protected ?Result $result = null;

    /**
     * Current Incident for Check.  As long as the incident is unresolved, this
     * will be that incident.  After it's resolved, it will be nulled.
     */
    protected ?Incident $incident = null;

    /**
     * Any misc meta data to attach to the Check.
     * @var mixed|null
     */
    protected $meta = null;

    public function __construct(
        $id,
        ?CommandInterface $command = null,
        ?ScheduleInterface $schedule = null
    ) {
        $this->id = $id;
        $this->command = $command;
        $this->schedule = $schedule;
    }

    public function withAttributes(array $attributes): self
    {
        $check = clone $this;
        $check->attributes = $attributes;

        return $check;
    }

    public function withCommand(?CommandInterface $command): self
    {
        $check = clone $this;
        $check->command = $command;

        return $check;
    }

    public function withSchedule(?ScheduleInterface $schedule): self
    {
        $check = clone $this;
        $check->schedule = $schedule;

        return $check;
    }

    /**
     * @param HandlerInterface[] $handlers
     */
    public function withHandlers(array $handlers): self
    {
        $check = clone $this;
        $check->handlers = $handlers;

        return $check;
    }

    /**
     * @param mixed $meta
     */
    public function withMeta($meta): self
    {
        $check = clone $this;
        $check->meta = $meta;

        return $check;
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

    public function getSchedule(): ?ScheduleInterface
    {
        return $this->schedule;
    }

    public function setSchedule(?ScheduleInterface $schedule): self
    {
        $this->schedule = $schedule;

        return $this;
    }

    public function isDue(): bool
    {
        return !$this->schedule || $this->schedule->isDue($this);
    }

    public function getNextCheck(): int
    {
        return $this->schedule ? $this->schedule->timeDue($this) : Carbon::now()->getTimestamp();
    }

    public function getLastCheck(): ?int
    {
        return $this->lastCheck;
    }

    public function setLastCheck(?int $time): self
    {
        $this->lastCheck = $time;

        return $this;
    }

    public function setLastCheckNow(): self
    {
        $this->lastCheck = Carbon::now()->getTimestamp();

        return $this;
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

    public function runCommand(): PromiseInterface
    {
        try {
            return $this->command->run($this);
        } catch (\Throwable $e) {
            return \React\Promise\reject($e);
        }
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
