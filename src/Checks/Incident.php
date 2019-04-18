<?php
namespace SeanKndy\Poller\Checks;

use SeanKndy\Poller\Results\Result;
use Ramsey\Uuid\Uuid;
/**
 * Represents a check that underwent a state change from OK to non-OK.
 *
 */
class Incident
{
    /**
     * @var string
     */
    private $id;
    /**
     * @var string
     */
    private $externalId;
    /**
     * @var int
     */
    private $fromState;
    /**
     * @var int
     */
    private $toState;
    /**
     * @var string
     */
    private $reason;
    /**
     * Timestamps
     * @var int
     */
    private $addedTime;
    private $updatedTime;
    private $acknowledgedTime;
    private $resolvedTime;

    public function __construct($id, int $fromState, int $toState, string $reason = null,
        $resolved = null, $acknowledged = null, $added = null, $updated = null)
    {
        $this->id = $id ? $id : Uuid::uuid4()->toString();
        $this->fromState = $fromState;
        $this->toState = $toState;
        $this->addedTime = $added ? $added : time();
        $this->updatedTime = $updated ? $updated : time();
        $this->reason = $reason;
        $this->resolvedTime = $resolved;
        $this->acknowledgedTime = $acknowledged;
    }

    /**
     * Has incident been resolved?
     *
     * @return bool
     */
    public function isResolved()
    {
        return !!$this->resolvedTime;
    }

    /**
     * Has incident been acknowledged?
     *
     * @return bool
     */
    public function isAcknowledged()
    {
        return !!$this->acknowledgedTime;
    }

    public function getResolvedTime()
    {
        return $this->resolvedTime;
    }

    public function getAddedTime()
    {
        return $this->addedTime;
    }

    public function getUpdatedTime()
    {
        return $this->updatedTime;
    }

    public function getAcknowledged()
    {
        return $this->acknowledgedTime;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getExternalId()
    {
        return $this->externalId;
    }

    public function getFromState()
    {
        return $this->fromState;
    }

    public function getToState()
    {
        return $this->toState;
    }

    public function getReason()
    {
        return $this->reason;
    }

    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    public function setExternalId(string $id = null)
    {
        $this->externalId = $id;
        return $this;
    }

    public function setResolvedTime($time = null)
    {
        $this->resolvedTime = $time ? $time : time();
        return $this;
    }

    public function setAcknowledgedTime($time = null)
    {
        $this->acknowledgedTime = $time ? $time : time();
        return $this;
    }

    public function setAddedTime($time = null)
    {
        $this->addedTime = $time ? $time : time();
        return $this;
    }

    public function setUpdatedTime($time = null)
    {
        $this->updatedTime = $time ? $time : time();
        return $this;
    }

    public static function fromResults(Result $lastResult = null, Result $currentResult)
    {
        if ($lastResult === null) {
            $lastResult = new Result();
        }
        return new self(null, $lastResult->getState(), $currentResult->getState(), $currentResult->getStateReason());
    }
}
