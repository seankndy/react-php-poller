<?php
namespace SeanKndy\Poller\Results;

use Ramsey\Uuid\Uuid;
/**
 * Basic data structure for storing Result from Check Command
 *
 */
class Result
{
    const STATE_OK = 0;
    const STATE_WARN = 1;
    const STATE_CRIT = 2;
    const STATE_UNKNOWN = 3;


    /**
     * @var string
     */
    protected $id;
    /**
     * @var int
     */
    protected $state;
    /**
     * @var string
     */
    protected $stateReason = '';
    /**
     * @var MetricSet
     */
    protected $metrics;
    /**
     * Timestamp of result creation
     * @var int
     */
    protected $time;

    public function __construct(int $state = self::STATE_UNKNOWN,
        string $stateReason = null, array $metrics = [], int $time = 0)
    {
        $this->id = Uuid::uuid4()->toString();
        $this->state = $state;
        $this->stateReason = $stateReason;
        $this->setMetrics($metrics);
        $this->time = $time ? $time : \time();
    }

    /**
     * Set state
     *
     * @param int $state
     *
     * @return $this
     */
    public function setState($state)
    {
        $this->state = $state;
        return $this;
    }

    /**
     * Set state reason
     *
     * @param string $reason Description of why state is what it is
     *
     * @return $this
     */
    public function setStateReason(string $reason)
    {
        $this->stateReason = $reason;
        return $this;
    }

    /**
     * Get state reason
     *
     * @param string $reason Description of why state is what it is
     *
     * @return $this
     */
    public function getStateReason()
    {
        return $this->stateReason;
    }

    /**
     * Add Metrics
     *
     * @param array $metrics array of Metric objects
     *
     * @return $this
     */
    public function setMetrics(array $metrics)
    {
        $this->metrics = new MetricSet();
        foreach ($metrics as $metric) {
            $this->metrics->attach($metric);
        }
        return $this;
    }

    /**
     * Add Metric to the Result
     *
     * @return $this
     */
    public function addMetric(Metric $metric)
    {
        $this->metrics->attach($metric);
        return $this;
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
     * Get timestamp
     *
     * @return int
     */
    public function getTime()
    {
        return $this->time;
    }

    /**
     * Set timestamp
     *
     * @return int
     */
    public function setTime(int $time)
    {
        $this->time = $time;
    }

    /**
     * Get ID
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set ID
     *
     * @return $this
     */
    public function setId(string $id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get state string
     *
     * @return int
     */
    public function getStateString()
    {
        return self::stateIntToString($this->state);
    }

    /**
     * Get metrics
     *
     * @return array
     */
    public function getMetrics()
    {
        return \iterator_to_array($this->metrics);
    }

    /**
     * Return integer const value for string state name
     *
     * @param string $state State name
     *
     * @return int
     */
    public static function stateStringToInt(string $state = null)
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

    public static function stateIntToString(int $state)
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
     * Determine if Result is in OK state
     *
     * @param Result $result Result to check
     *
     * @return bool
     */
    public static function isOK(Result $result)
    {
        return ($result->getState() === self::STATE_OK);
    }

    /**
     * When cloned, clone metrics as well
     *
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
