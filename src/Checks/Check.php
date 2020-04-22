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
    /**
     * @var int
     */
    protected $state;
    /**
     * Interval in seconds the Check is due to run.
     * A value <=0 means the Check should not be re-enqueued()
     * @var int
     */
    protected $interval;
    /**
     * @var bool
     */
    protected $incidentsSuppressed = false;
    /**
     * @var CommandInterface
     */
    protected $command;
    /**
     * Attributes passed to the Command.
     * @var array
     */
    protected $attributes = [];
    /**
     * @var int
     */
    protected $lastCheck = null;
    /**
     * @var int
     */
    protected $nextCheck = null;
    /**
     * @var HandlerInterface[]
     */
    protected $handlers = [];
    /**
     * Current Result for the check, may be null
     * @var Result
     */
    protected $result = null;
    /**
     * @var int
     */
    protected $lastStateDuration = 0;
    /**
     * @var float
     */
    protected $stateChangeTime = 0;
    /**
     * @var int
     */
    protected $lastState = self::STATE_NEW;
    /**
     * Current Incident for Check.  As long as the incident is unresolved, this
     * will be that incident.  After it's resolved, it will be nulled.
     * @var Incident
     */
    protected $incident;
    /**
     * Any misc meta data to attach to the Check.
     * @var mixed
     */
    protected $meta;


    /**
     * Time this Check object was 'changed' such as
     * updated attributes, handlers or interval
     * Updated state, result and lastCheck does not count as 'changed'
     * @var int
     */
    protected $lastChanged;

    public function __construct($id, CommandInterface $command = null,
        array $attributes, int $nextCheck, int $interval, Result $result = null,
        array $handlers = [], Incident $incident = null, $meta = null)
    {
        $this->id = $id;
        $this->command = $command;
        $this->attributes = $attributes;
        $this->setState(self::STATE_IDLE);
        $this->nextCheck = $nextCheck;
        $this->interval = $interval;
        $this->result = $result;
        $this->handlers = $handlers;
        $this->lastChanged = time();
        $this->incident = $incident;
        $this->meta = $meta;
    }

    /**
     * Set state
     *
     * @param $state int State of check
     *
     * @return $this
     */
    public function setState(int $state)
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

    /**
     * Is the check due?
     *
     * @param $time Timestamp of now
     *
     * @return boolean
     */
    public function isDue($time = null)
    {
        if ($time == null) $time = \time();
        return ($this->state != self::STATE_EXECUTING
            && $this->nextCheck && $time >= $this->nextCheck);

        /*
        return ($this->state != self::STATE_EXECUTING && (!$this->lastCheck
            || $time >= $this->timeOfNextCheck()));
        */
    }

    /**
     * Set attributes passed to the Command
     *
     * @param array $attributes Attribute key/values
     *
     * @return void
     */
    public function setAttributes(array $attributes)
    {
        $this->attributes = $attributes;
        $this->lastChanged = time();
        return $this;
    }

    /**
     * Set attribute passed to the Command
     *
     * @param string $key Attribute key
     * @param mixed  $val Attribute value
     *
     * @return void
     */
    public function setAttribute($key, $val)
    {
        $this->attributes[$key] = $val;
        $this->lastChanged = time();
        return $this;
    }

    /**
     * Get attribute
     *
     * @param string $key Attribute key
     *
     * @return mixed
     */
    public function getAttribute($key)
    {
        return isset($this->attributes[$key]) ? $this->attributes[$key] : null;
    }

    /**
     * Get all attributes
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * Get Command
     *
     * @return array
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * Set Command
     *
     * @param CommandInterface $cmd Command to set
     *
     * @return $this
     */
    public function setCommand(CommandInterface $cmd)
    {
        $this->command = $cmd;
        $this->lastChanged = time();
        return $this;
    }

    /**
     * Set Result\Handler's
     *
     * @return $this
     */
    public function setHandlers(array $handlers)
    {
        $this->handlers = $handlers;
        $this->lastChanged = time();
        return $this;
    }

    /**
     * Add a Results\Handlers\HandlerInterface
     *
     * @return $this
     */
    public function addHandler(HandlerInterface $handler)
    {
        $this->handlers[] = $handler;
        return $this;
    }

    /**
     * Get the Result handlers
     *
     * @return array
     */
    public function getHandlers()
    {
        return $this->handlers;
    }

    /**
     * Get state
     *
     * @return int
     */
    public function getState()
    {
        return $this->state;
    }


    /**
     * Get incident
     *
     * @return Incident
     */
    public function getIncident()
    {
        return $this->incident;
    }

    /**
     * Set an incident
     *
     * @return Incident
     */
    public function setIncident(Incident $incident = null)
    {
        $this->incident = $incident;
        return $this;
    }

    /**
     * Set whether incident suppression is enabled or not
     *
     * @var bool $flag
     *
     * @return $this
     */
    public function setIncidentsSuppressed(bool $flag)
    {
        $this->incidentsSuppressed = $flag;
        return $this;
    }

    /**
     * Are incidents suppressed?
     *
     * @return bool
     */
    public function areIncidentsSuppressed()
    {
        return $this->incidentsSuppressed;
    }

    /**
     * Get interval
     *
     * @return int
     */
    public function getInterval()
    {
        return $this->interval;
    }

    /**
     * Set Result object
     *
     * @param Result $result Current result
     *
     * @return self
     */
    public function setResult(Result $result)
    {
        $this->result = $result;
        return $this;
    }

    /**
     * Get Result object
     *
     * @return Result|null
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Get time between last state changes
     *
     * @return float
     */
    public function getLastStateDuration()
    {
        return $this->lastStateDuration;
    }

    /**
     * Get next check time
     *
     * @return int
     */
    public function getNextCheck()
    {
        return $this->nextCheck;
    }

    /**
     * Set next check time
     *
     * @param int $time Timestamp
     *
     * @return $this
     */
    public function setNextCheck(int $time = null)
    {
        if ($time !== null) {
            $this->nextCheck = $time;
        } else if ($this->interval > 0) {
            $this->nextCheck += $this->interval;
        } else {
            $this->nextCheck = null;
        }
        return $this;
    }

    /**
     * Get last check time
     *
     * @return int
     */
    public function getLastCheck()
    {
        return $this->lastCheck;
    }

    /**
     * Set last check time
     *
     * @param int $time Timestamp
     *
     * @return $this
     */
    public function setLastCheck(int $time = null)
    {
        $this->lastCheck = $time !== null ? $time : \time();
        return $this;
    }

    /**
     * Get ID for this Check
     *
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set metadata
     *
     * @return $this
     */
    public function setMeta($meta)
    {
        $this->meta = $meta;
        return $this;
    }

    /**
     * Get metadata
     *
     * @return mixed
     */
    public function getMeta()
    {
        return $this->meta;
    }

    /**
     * Get last change time
     *
     * @return int
     */
    public function getLastChanged()
    {
        return $this->lastChanged;
    }

    /**
     * Is this check up to date according to timestamp $time
     *
     * @return bool
     */
    public function isUpToDate($time)
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
     *
     * @return int
     */
    public function timeToNextCheck($time = null)
    {
        $time = $time !== null ? $time : time();
        return ($this->getNextCheck() - $time);
        /*
        return $this->getLastCheck()
            ? $this->getInterval() - ($time - $this->getLastCheck())
            : -($this->getInterval() * 10);
        */
    }

    /**
     * Time when check becomes due, deprecated in favor of getNextCheck()
     *
     * @return int
     */
    public function timeOfNextCheck()
    {
        return $this->getNextCheck();
    }

    /**
     * Determine if new Incident is warranted based on the new Result.
     *
     * @return bool
     */
    public function isNewIncident(Result $currentResult)
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
