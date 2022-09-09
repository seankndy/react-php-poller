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


    protected int $type;

    protected string $name;

    /**
     * @var mixed
     */
    protected $value = null;

    protected int $time;

    public function __construct(int $type, string $name, $value = null, ?int $time = null)
    {
        if (!$time) $time = \time();

        $this->type = $type;
        $this->name = $name;
        $this->setValue($value);
        $this->time = $time;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value): self
    {
        if (($this->type == self::TYPE_GAUGE || $this->type == self::TYPE_COUNTER)
            && !\is_numeric($value)) {
            $value = 0;
        }
        $this->value = $value;

        return $this;
    }

    public function getTime(): ?int
    {
        return $this->time;
    }
}
