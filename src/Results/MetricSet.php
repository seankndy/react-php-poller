<?php
namespace SeanKndy\Poller\Results;
/**
 * Metric object set.  Unique Metric defined as the same name + time
 *
 */
class MetricSet extends \SplObjectStorage
{
    public function getHash($obj)
    {
        return $obj->getName() . ':' . $obj->getTime();
    }
}
