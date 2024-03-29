<?php

namespace SeanKndy\Poller\Checks;

use Carbon\Carbon;
use SeanKndy\Poller\Results\Result;
use Ramsey\Uuid\Uuid;

/**
 * Represents an incident (check that underwent a non-OK state change).
 *
 */
class Incident
{
    /**
     * @var mixed
     */
    private $id;
    /**
     * @var mixed
     */
    private $externalId;

    private int $fromState;

    private int $toState;

    private ?string $reason;

    private int $addedTime;

    private int $updatedTime;

    private ?int $acknowledgedTime;

    private ?int $resolvedTime;

    public function __construct(
               $id,
        int    $fromState,
        int    $toState,
        string $reason = null,
        ?int   $resolvedTime = null,
        ?int   $acknowledgedTime = null,
        ?int   $addedTime = null,
        ?int   $updatedTime = null
    ) {
        $this->id = $id ?: Uuid::uuid4()->toString();
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->reason = $reason;
        $this->resolvedTime = $resolvedTime;
        $this->acknowledgedTime = $acknowledgedTime;
        $this->addedTime = $addedTime ?: Carbon::now()->getTimestamp();
        $this->updatedTime = $updatedTime ?: Carbon::now()->getTimestamp();
    }

    /**
     * Given a previous Result object and current Result object, make from them
     * a new Incident.
     *
     * @param Result|null $lastResult Previous check Result
     * @param Result $currentResult Current check Result
     *
     * @return self
     */
    public static function fromResults(?Result $lastResult, Result $currentResult): self
    {
        if ($lastResult === null) {
            $lastResult = new Result();
        }

        return new self(null, $lastResult->getState(), $currentResult->getState(), $currentResult->getStateReason());
    }

    public function isResolved(): bool
    {
        return !!$this->resolvedTime;
    }

    public function isAcknowledged(): bool
    {
        return !!$this->acknowledgedTime;
    }

    public function getResolvedTime(): ?int
    {
        return $this->resolvedTime;
    }

    public function getAddedTime(): int
    {
        return $this->addedTime;
    }

    public function getUpdatedTime(): int
    {
        return $this->updatedTime;
    }

    public function getAcknowledgedTime(): ?int
    {
        return $this->acknowledgedTime;
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getExternalId()
    {
        return $this->externalId;
    }

    public function getFromState(): int
    {
        return $this->fromState;
    }

    public function getToState(): int
    {
        return $this->toState;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * @param mixed $id
     */
    public function setId($id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * @param mixed $id
     */
    public function setExternalId($id = null): self
    {
        $this->externalId = $id;

        return $this;
    }

    public function resolve(): self
    {
        $this->resolvedTime = Carbon::now()->getTimestamp();

        return $this;
    }

    public function acknowledge(): self
    {
        $this->acknowledgedTime = Carbon::now()->getTimestamp();

        return $this;
    }
}
