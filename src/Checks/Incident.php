<?php
namespace SeanKndy\Poller\Checks;

use SeanKndy\Poller\Results\Result;
use Ramsey\Uuid\Uuid;
/**
 * Represents an incident (check that underwent a non-OK state change).
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
     * Reason for incident
     * @var string
     */
    private $reason;
    /**
     * Timestamp incident created
     * @var int
     */
    private $addedTime;
    /**
     * Timestamp incident updated
     * @var int
     */
    private $updatedTime;
    /**
     * Timestamp incident acknowledged
     * @var int
     */
    private $acknowledgedTime = null;
    /**
     * Timestamp incident resolved
     * @var int
     */
    private $resolvedTime = null;

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
     * Given a previous Result object and current Result object, make from them
     * a new Incident.
     *
     * @param Result $lastResult Previous check Result
     * @param Result $currentResult Current check Result
     *
     * @return self
     */
    public static function fromResults(Result $lastResult = null, Result $currentResult)
    {
        if ($lastResult === null) {
            $lastResult = new Result();
        }
        return new self(null, $lastResult->getState(), $currentResult->getState(), $currentResult->getStateReason());
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

    /**
     * Get value of resolvedTime
     *
     * @return int
     */
    public function getResolvedTime()
    {
        return $this->resolvedTime;
    }

    /**
     * Get value of addedTime
     *
     * @return int
     */
    public function getAddedTime()
    {
        return $this->addedTime;
    }

    /**
     * Get value of updatedTime
     *
     * @return int
     */
    public function getUpdatedTime()
    {
        return $this->updatedTime;
    }

    /**
     * Get value of acknowledgedTime
     *
     * @return int
     */
    public function getAcknowledgedTime()
    {
        return $this->acknowledgedTime;
    }

    /**
     * Get value of id
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get value of externalId
     *
     * @return string
     */
    public function getExternalId()
    {
        return $this->externalId;
    }

    /**
     * Get value of fromState
     *
     * @return int
     */
    public function getFromState()
    {
        return $this->fromState;
    }

    /**
     * Get value of toState
     *
     * @return int
     */
    public function getToState()
    {
        return $this->toState;
    }

    /**
     * Get value of reason
     *
     * @return string
     */
    public function getReason()
    {
        return $this->reason;
    }

    /**
     * Set value of id
     *
     * @param $id string
     *
     * @return self
     */
    public function setId(string $id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Set value of externalId
     *
     * @param $id string
     *
     * @return self
     */
    public function setExternalId(string $id = null)
    {
        $this->externalId = $id;

        return $this;
    }

    /**
     * Set value of resolvedTime
     *
     * @param $time int
     *
     * @return self
     */
    public function setResolvedTime($time = null)
    {
        $this->resolvedTime = $time ? $time : time();

        return $this;
    }

    /**
     * Set value of acknowledgedTime
     *
     * @param $time int
     *
     * @return self
     */
    public function setAcknowledgedTime($time = null)
    {
        $this->acknowledgedTime = $time ? $time : time();

        return $this;
    }

    /**
     * Set value of addedTime
     *
     * @param $time int
     *
     * @return self
     */
    public function setAddedTime($time = null)
    {
        $this->addedTime = $time ? $time : time();

        return $this;
    }

    /**
     * Set value of updatedTime
     *
     * @param $time int
     *
     * @return self
     */
    public function setUpdatedTime($time = null)
    {
        $this->updatedTime = $time ? $time : time();

        return $this;
    }
}
