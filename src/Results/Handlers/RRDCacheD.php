<?php

namespace SeanKndy\Poller\Results\Handlers;

use React\Filesystem\Filesystem;
use React\Filesystem\FilesystemInterface;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Checks\Schedules\Periodic;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric;
use React\Socket\ConnectionInterface;
use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Result Handler that asynchronously creates RRD files
 * and interfaces with the rrdcached UNIX socket to submit UPDATEs to
 * RRD files.
 *
 */
class RRDCacheD implements HandlerInterface
{
    private LoopInterface $loop;

    private LoggerInterface $logger;

    private string $rrdDir;

    private string $rrdToolBin;

    private string $rrdCachedSockFile;

    private Connector $rrdCachedConnector;

    private FilesystemInterface $filesystem;

    /**
     * Constructor has sync filesystem calls, so it should only be called once
     * during init.
     *
     */
    public function __construct(
        LoopInterface $loop,
        LoggerInterface $logger,
        string $rrdDir,
        string $rrdToolBin = null,
        string $rrdCachedSockFile = '/var/run/rrdcached.sock'
    ) {
        $this->loop = $loop;
        $this->logger = $logger;

        if ($rrdToolBin === null) {
            foreach ([
                '/usr/bin/rrdtool',
                '/usr/local/bin/rrdtool',
                '/opt/homebrew/bin/rrdtool',
            ] as $possibleLocation) {
                if (\file_exists($possibleLocation)) {
                    $rrdToolBin = $possibleLocation;
                    break;
                }
            }

            if (! $rrdToolBin) {
                throw new \RuntimeException("rrdtool binary could not be found.");
            }
        } else if (!\file_exists($rrdToolBin)) {
            throw new \RuntimeException("rrdtool binary '$rrdToolBin does not exist!");
        }
        $this->rrdToolBin = $rrdToolBin;

        if (!\is_dir($rrdDir)) {
            throw new \RuntimeException("'$rrdDir' is not a directory or does not exist!");
        }
        $this->rrdDir = $rrdDir;

        $this->rrdCachedSockFile = $rrdCachedSockFile;

        $this->filesystem = Filesystem::create($this->loop);
        $this->rrdCachedConnector = new Connector($this->loop, [
            'tcp' => false,
            'tls' => false,
            'unix' => true,
            'dns' => false
        ]);
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
        // no metrics? no run.  no Periodic schedule? no run.
        if (!$result->getMetrics() || !($check->getSchedule() instanceof Periodic)) {
            return \React\Promise\resolve([]);
        }

        return $this->initFileStructure($check, $result)->then(
            fn() => $this->rrdCachedConnect()->then(
                fn(ConnectionInterface $connection) => $this->writeRrdUpdateBatch($connection, $check, $result)
            )
        );
    }

    /**
     * Initialize file structure.
     */
    private function initFileStructure(Check $check, Result $result): PromiseInterface
    {
        $createRrdFiles = function () use ($check, $result) {
            $promises = [];
            foreach ($result->getMetrics() as $metric) {
                $promises[] = $this->filesystem->file(
                    $this->getRrdFilePath($check, $metric)
                )->exists()->otherwise(function($e) use ($metric, $check) {
                    $rrdFile = $this->getRrdFilePath($check, $metric);
                    $type = ($metric->getType() == Metric::TYPE_COUNTER
                        ? 'COUNTER' : 'GAUGE');
                    $interval = $check->getSchedule()->getInterval();
                    $label = $this->getRrdDsName($metric);

                    $this->log(LogLevel::DEBUG, "RRD file $rrdFile does not exist, attempting to create.");

                    $cmd = $this->rrdToolBin . " create $rrdFile " .
                        "-b " . ($metric->getTime()-10) . " -s $interval ";
                    $cmd .= "DS:$label:$type:" . ($interval*2) . ":U:U ";
                    $cmd .= $this->buildRras($interval);

                    $deferred = new \React\Promise\Deferred();

                    $process = new \React\ChildProcess\Process("exec $cmd");
                    $process->start($this->loop);
                    $process->on('exit',
                        function($exitCode, $termSignal) use ($deferred, $rrdFile) {
                            if ($exitCode != 0) {
                                $this->log(LogLevel::DEBUG, "RRD file $rrdFile failed to create (via rrdtool create)");
                                $deferred->reject(
                                    new \Exception("rrdtool create error; exit=$exitCode")
                                );
                            } else {
                                $this->log(LogLevel::DEBUG, "RRD file $rrdFile created.");
                                $deferred->resolve([]);
                            }
                        }
                    );

                    return $deferred->promise();
                });
            }

            return \React\Promise\all($promises);
        };

        $svcRrdDir = $this->getRrdBaseDir($check);
        return $this->filesystem->dir($svcRrdDir)->stat()->then(
            $createRrdFiles,
            function ($e) use ($svcRrdDir, $createRrdFiles) {
                $this->log(LogLevel::DEBUG, "Directory stat() for $svcRrdDir failed (possibly just due to not found): " . $e->getMessage());

                // this is sometimes failing with unknown error instead of just 'no such file'
                return $this->filesystem->dir($svcRrdDir)->create('rwxr-xr-x')
                       ->then($createRrdFiles);
            }
        );
    }

    /**
     * Connect to rrd cached socket
     */
    private function rrdCachedConnect(): PromiseInterface
    {
        if (!\file_exists($this->rrdCachedSockFile)) {
            throw new \RuntimeException("RRDCacheD Sock File '$this->rrdCachedSockFile' does not exist!");
        }

        return $this->rrdCachedConnector->connect('unix://' . $this->rrdCachedSockFile)->then(
            function (ConnectionInterface $connection) {
                return $connection;
            },
            function (\Exception $e) {
                $this->log(LogLevel::DEBUG, "RRD socket connect failure: " . $e->getMessage());
                throw new \Exception("Failed to connect to RRDCacheD socket: " .
                    $e->getMessage());
            }
        );
    }

    /**
     * Helper method to write to rrdcached socket and then read/parse response
     */
    private function rrdCachedWrite(ConnectionInterface $connection, string $dataToWrite): PromiseInterface
    {
        if (!$connection->isWritable()) {
            return \React\Promise\reject(new \Exception("Connection not writable!"));
        }

        $deferred = new \React\Promise\Deferred();
        if (!$connection->write($dataToWrite)) {
            $this->log(LogLevel::DEBUG, "Write temp failed to RRD socket!");

            $connection->once('drain', function() use ($deferred, $dataToWrite, $connection) {
               $deferred->resolve($this->rrdCachedWrite($connection, $dataToWrite));
            });
        } else {
            $connection->once('data', function ($data) use ($deferred) {
                list($code,$msg) = $this->parseServerLn($data);
                if ($code < 0) {
                    $deferred->reject(new \Exception("Error response from RRDCacheD: " . $msg));
                } else {
                    $deferred->resolve([$code,$msg]);
                }
            });
        }
        return $deferred->promise();
    }

    /**
     * Log helper/formatter
     */
    private function log($level, string $message, Check $check = null): void
    {
        $msg = "RRDCacheD Handler: ";
        if ($check) {
            $msg .= "Check ID=<{$check->getId()}> -- ";
        }
        $msg .= $message;
        $this->logger->log($level, $msg);
    }

    /**
     * Write a batch of UPDATEs to rrdcached socket
     */
    private function writeRrdUpdateBatch(
        ConnectionInterface $connection,
        Check $check,
        Result $result
    ): PromiseInterface {
        return $this->rrdCachedWrite($connection, 'BATCH'.PHP_EOL)->then(
            function ($codeAndMsg) use ($connection, $check, $result) {
                list($code,$msg) = $codeAndMsg;
                $this->log(LogLevel::DEBUG, "RRD response to BATCH: code=<$code>; msg=<$msg>", $check);

                $write = [];
                foreach ($result->getMetrics() as $metric) {
                    $rrdFile = $this->getRrdFilePath($check, $metric);
                    $options = [
                        $metric->getTime(),
                        $metric->getType() == Metric::TYPE_COUNTER ?
                            (int)$metric->getValue() : $metric->getValue()
                    ];
                    $cmd = 'UPDATE '.$rrdFile.' '.implode(':', $options);
                    $write[] = $cmd;
                }
                $write[] = '.'.PHP_EOL;

                return $this->rrdCachedWrite($connection, implode(PHP_EOL, $write))->then(function ($codeAndMsg) use ($check) {
                    list($code,$msg) = $codeAndMsg;
                    $this->log(LogLevel::DEBUG, "RRD response to batch UPDATEs: code=<$code>; msg=<$msg>", $check);
                    return $codeAndMsg;
                });
            },
            function ($e) use ($check, $connection) {
                $this->log(LogLevel::DEBUG, "RRD failed to begin BATCH: " . $e->getMessage(), $check);
                $connection->end('QUIT'.PHP_EOL);
                $connection->close();
                $connection = null;
                throw $e;
            }
        )->then(function ($codeAndMsg) use ($connection) {
            $connection->end('QUIT'.PHP_EOL);
            $connection->close();
            $connection = null;
            return true;
        });
    }

    /**
     * Given an interval, generate the RRD RRA definitions for the CREATE command
     */
    private function buildRras(int $interval): string
    {
        $cmd = '';

        // define sample time frames
        $weekly_avg  = 1800;  // 30m
        $monthly_avg = 7200; // 2h
        $yearly_avg  = 43200; // 12h

        // define archives
        // Holt-Winters forecasting
        //$cmd .= "RRA:HWPREDICT:" . (86400 / $interval) . ":0.1:0.0035:" . (86400 / $interval) . " ";

        // daily MIN, $interval second avg
        $cmd .= "RRA:MIN:0.5:1:" . (86400 / $interval) . " ";
        // weekly MIN, 30m average
        $cmd .= "RRA:MIN:0.5:" . ($weekly_avg / $interval) . ":" .
            (86400 * 7 / $interval / ($weekly_avg / $interval)) . " ";
        // monthly MIN, 2h average
        $cmd .= "RRA:MIN:0.5:" . ($monthly_avg / $interval) . ":" .
            (86400 * 31 / $interval / ($monthly_avg / $interval)) . " ";
        // yearly MIN, 12h average
        $cmd .= "RRA:MIN:0.5:" . ($yearly_avg / $interval) . ":" .
            (86400 * 366 / $interval / ($yearly_avg / $interval)) . " ";

        // daily AVERAGE, $interval second avg
        $cmd .= "RRA:AVERAGE:0.5:1:" . (86400 / $interval) . " ";
        // weekly AVERAGE, 30m average
        $cmd .= "RRA:AVERAGE:0.5:" . ($weekly_avg / $interval) . ":" .
            (86400 * 7 / $interval / ($weekly_avg / $interval)) . " ";
        // monthly AVERAGE, 2h average
        $cmd .= "RRA:AVERAGE:0.5:" . ($monthly_avg / $interval) . ":" .
            (86400 * 31 / $interval / ($monthly_avg / $interval)) . " ";
        // yearly AVERAGE, 12h average
        $cmd .= "RRA:AVERAGE:0.5:" . ($yearly_avg / $interval) . ":" .
            (86400 * 366 / $interval / ($yearly_avg / $interval)) . " ";

        // daily MAX, $interval second avg
        $cmd .= "RRA:MAX:0.5:1:" . (86400 / $interval) . " ";
        // weekly MAX, 30m average
        $cmd .= "RRA:MAX:0.5:" . ($weekly_avg / $interval) . ":" .
            (86400 * 7 / $interval / ($weekly_avg / $interval)) . " ";
        // monthly MAX, 2h average
        $cmd .= "RRA:MAX:0.5:" . ($monthly_avg / $interval) . ":" .
            (86400 * 31 / $interval / ($monthly_avg / $interval)) . " ";
        // yearly MAX, 12h average
        $cmd .= "RRA:MAX:0.5:" . ($yearly_avg / $interval) . ":" .
            (86400 * 366 / $interval / ($yearly_avg / $interval)) . " ";

        return $cmd;
    }

    /**
     * Get the RRD DS name for a Metric
     */
    private function getRrdDsName(Metric $metric): string
    {
        $label = $metric->getName();
        // RRD DS can only be 19 chars max
        if (strlen($label) > 19) {
            $label = substr($label, 0, 19);
        }
        return $label;
    }

    /**
     * Get file path of the check/metric RRD
     */
    private function getRrdFilePath(Check $check, Metric $metric): string
    {
        return $this->rrdDir . "/" . $check->getId() . '/' .
            $this->getRrdDsName($metric) . '.rrd';
    }

    /**
     * Get base RRD storage directory for the check
     */
    private function getRrdBaseDir(Check $check): string
    {
        return $this->rrdDir . "/" . $check->getId();
    }

    /**
     * Parse response line from RRDCacheD
     *
     * @return array{0: int, 1: string}
     */
    private function parseServerLn(string $line): array
    {
        $space = strpos($line, ' ');
        $code = (int) substr($line, 0, $space);
        $message = trim(substr($line, $space + 1));

        return [$code, $message];
    }
}
