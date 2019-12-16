<?php
namespace SeanKndy\Poller\Results\Handlers;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric;
use React\Socket\ConnectionInterface;
use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
/**
 * Result handler for sending metrics to a statsd daemon
 * NOTE: You proably need to override getMetricNamePrefix()
 *
 */
class StatsD implements HandlerInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var string
     */
    private $host;
    /**
     * @var int
     */
    private $port;

    /**
     *
     */
    public function __construct(LoopInterface $loop, LoggerInterface $logger,
        string $host, int $port)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * {@inheritDoc}
     */
    public function mutate(Check $check, Result $result, Incident $incident = null)
    {
        return \React\Promise\resolve([]);
    }

    /**
     * {@inheritDoc}
     */
    public function process(Check $check, Result $result, Incident $incident = null)
    {
        if (!$result->getMetrics()) { // no metrics? no run.
            return \React\Promise\resolve([]);
        }

        $factory = new \React\Datagram\Factory($this->loop);
        return $factory->createClient($this->host.':'.$this->port)->then(
            function (\React\Datagram\Socket $client) use ($check, $result) {
                $client->send($this->buildProtocolMessage($check, $result));

                $client->on('error', function(\Throwable $error, $client) {
                    $this->logger->log(
                        LogLevel::ERROR,
                        "Failed to send metrics to statsd: " . $error->getMessage()
                    );
                });
            }, function(\Throwable $error) {
                $this->logger->log(
                    LogLevel::ERROR,
                    "Failed to connect to statsd server: " . $error->getMessage()
                );
            }
        );
    }

    /**
     * Retrieve metric name prefix/namespace
     * Feel free to override in your own implementation
     * (ex. if your Metric's $name property has no prefix/namespace, you probably
     *  will need this method to dictate some kind of namespace for the metric
     *  such as 'network.wi.greenbay.rtr-a.')
     *
     * @param Check $check
     * @param Result $result
     *
     * @return string
     */
    protected function getMetricNamePrefix(Check $check, Result $result)
    {
        return '';
    }

    /**
     * Build statsd protocol message
     *
     * @return string
     */
    private function buildProtocolMessage(Check $check, Result $result)
    {
        $prefix = \rtrim($this->getMetricNamePrefix($check, $result), '.');
        $msg = '';
        foreach ($result->getMetrics() as $metric) {
            $msg .= $prefix.'.'.$metric->getName().':'.$metric->getValue().'|'.
                ($metric->getType() == Metric::TYPE_COUNTER ? 'c' : 'g') . "\n";
        }
        return \trim($msg);
    }
}
