<?php
namespace SeanKndy\Poller\Results;
/**
 * Basic structure for holding a metric result
 *
 */
class Metric
{
    const TYPE_COUNTER = 0;
    const TYPE_GAUGE = 1;
    const TYPE_STRING = 2;

    /**
     * @var int
     */
    protected $type;
    /**
     * @var string
     */
    protected $name;
    /**
     * @var mixed
     */
    protected $value = null;
    /**
     * @var int
     */
    protected $time;

    public function __construct(int $type, string $name, $value = null, $time = null)
    {
        $this->type = $type;
        $this->name = $name;
        $this->setValue($value);
        if (!$time) $time = time();
        $this->time = $time;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        if (($this->type == self::TYPE_GAUGE || $this->type == self::TYPE_COUNTER)
            && !\is_numeric($value)) {
            $value = 0;
        }
        $this->value = $value;
        return $this;
    }

    public function getTime()
    {
        return $this->time;
    }
}
