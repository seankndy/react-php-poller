<?php

namespace SeanKndy\Poller\Results;

/**
 * Metric object set.  Unique Metric defined as the same name + time
 *
 */
class MetricSet extends \SplObjectStorage
{
    public function getHash($object): string
    {
        return $object->getName() . ':' . $object->getTime();
    }
}
