<?php

namespace SeanKndy\Poller\Results\Handlers;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;
use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Result handler for sending metrics to a statsd daemon
 * NOTE: You probably need to override getMetricNamePrefix()
 *
 */
class StatsD implements HandlerInterface
{
    protected LoopInterface $loop;

    protected LoggerInterface $logger;

    protected string $host;

    protected int $port;

    public function __construct(
        LoopInterface $loop,
        LoggerInterface $logger,
        string $host,
        int $port
    ) {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->host = $host;
        $this->port = $port;
    }

    /**
     * {@inheritDoc}
     */
    public function mutate(Check $check, Result $result, ?Incident $newIncident = null): PromiseInterface
    {
        return \React\Promise\resolve([]);
    }

    /**
     * {@inheritDoc}
     */
    public function process(Check $check, Result $result, ?Incident $newIncident = null): PromiseInterface
    {
        if (!$result->getMetrics()) { // no metrics? no run.
            return \React\Promise\resolve([]);
        }

        $factory = new \React\Datagram\Factory($this->loop);
        return $factory->createClient($this->host.':'.$this->port)->then(
            function (\React\Datagram\Socket $client) use ($check, $result) {
                $msg = $this->buildProtocolMessage($check, $result);

                $this->logger->log(
                    LogLevel::DEBUG,
                    'Sending following datagram to '.$this->host.':'.$this->port.': ' .
                        \str_replace("\n", "\\n", $msg)
                );

                $client->send($msg);
                $client->end(); // end() will wait for send buffer to drain
                $client->on('error', function(\Throwable $error, $client) {
                    $this->logger->log(
                        LogLevel::ERROR,
                        ($err = "Failed to send metrics to statsd: " . $error->getMessage())
                    );
                    throw new \Exception($err);
                });
            }, function(\Throwable $error) {
                $this->logger->log(
                    LogLevel::ERROR,
                    ($err = "Failed to connect to statsd server: " . $error->getMessage())
                );
                throw new \Exception($error->getMessage());
            }
        );
    }

    /**
     * Retrieve metric name prefix/namespace
     * Feel free to override in your own implementation
     * (ex. if your Metric's $name property has no prefix/namespace, you probably
     *  will need this method to dictate some kind of namespace for the metric
     *  such as 'network.wi.greenbay.rtr-a.')
     */
    protected function getMetricNamePrefix(Check $check, Result $result): string
    {
        return '';
    }

    /**
     * Build statsd protocol message
     */
    private function buildProtocolMessage(Check $check, Result $result): string
    {
        $prefix = \rtrim($this->getMetricNamePrefix($check, $result), '.');
        $msg = '';
        foreach ($result->getMetrics() as $metric) {
            if ($metric->getValue() < 0) {
                // see https://github.com/statsd/statsd/blob/master/docs/metric_types.md#gauges
                $msg .= "$prefix.".$metric->getName().":0|g\n";
            }
	        $msg .= $prefix.'.'.$metric->getName().':'.$metric->getValue()."|g\n";
        }
        return \trim($msg);
    }
}
