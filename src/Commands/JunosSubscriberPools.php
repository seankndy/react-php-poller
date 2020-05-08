<?php
namespace SeanKndy\Poller\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
/**
 * Command to check subscriber pool utilization on JunOS
 *
 */
final class JunosSubscriberPools implements CommandInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;
    /**
     * Path to snmpwalk binary
     * @var string
     */
    private $snmpGetBin;
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoopInterface $loop, LoggerInterface $logger,
        $snmpGetBin = '/usr/bin/snmpget')
    {
        $this->loop = $loop;
        $this->logger = $logger;

        if (!\file_exists($snmpGetBin)) {
            throw new \Exception("snmpget binary '$snmpGetBin' not found!");
        }
        $this->snmpGetBin = $snmpGetBin;
    }

    /**
     * {@inheritDoc}
     */
    public function run(Check $check)
    {
        // set default attributes
        $attributes = array_merge([
            'ip' => '',
            'snmp_read_community' => 'public',
            'pool_indexes' => '', // comma-separated list of snmp pool indexes
            'warn_percent' => 97,
            'crit_percent' => 99
        ], $check->getAttributes());

        $deferred = new \React\Promise\Deferred();
        $stdoutBuffer = '';

        $command = "{$this->snmpGetBin} -v 2c -c {$attributes['snmp_read_community']} -OQ -Os {$attributes['ip']} ";
        foreach (\preg_split('/[,\s\r\n]+/', $attributes['pool_indexes']) as $index) {
            foreach ([
                'JUNIPER-USER-AAA-MIB::jnxUserAAAAccessPoolAddressTotal.%d',
                'JUNIPER-USER-AAA-MIB::jnxUserAAAAccessPoolAddressesInUse.%d'
            ] as $mibfmt) {
                $command .= sprintf($mibfmt, $index) . ' ';
            }
        }
        $command = \rtrim($command, ' ');
        $this->logger->debug(__CLASS__ . ": command=$command");

        $process = new \React\ChildProcess\Process($command);
        $process->start($this->loop);
        $process->stdout->on('data', function ($chunk) use (&$stdoutBuffer) {
            $stdoutBuffer .= $chunk;
        });
        $process->on('exit', function($exitCode, $termSignal) use ($deferred,
            $attributes, &$stdoutBuffer) {
            $totalSpace = $usedSpace = 0;
            foreach (\preg_split('/[\r\n]+/', $stdoutBuffer) as $line) {
                [$mib, $value] = \explode(' = ', \trim($line));
                [$mib, $index] = \explode('.', $mib);

                if ($mib == 'jnxUserAAAAccessPoolAddressTotal') {
                    $totalSpace += $value;
                } else if ($mib == 'jnxUserAAAAccessPoolAddressesInUse') {
                    $usedSpace += $value;
                }
            }

            $this->logger->debug(__CLASS__ . ": totalSpace=$totalSpace");
            $this->logger->debug(__CLASS__ . ": usedSpace=$usedSpace");

            $percentUsedSpace = $usedSpace / $totalSpace * 100.0;

            if ($percentUsedSpace >= $attributes['crit_percent']) {
                $state =  Result::STATE_CRIT;
                $stateReason = "Percent utilization hit critical threshold ($percentUsedSpace%)";
            } else if ($percentUsedSpace >= $attributes['warn_percent']) {
                $state =  Result::STATE_WARN;
                $stateReason = "Percent utilization hit warning threshold ($percentUsedSpace%)";
            } else {
                $state = Result::STATE_OK;
                $stateReason = '';
            }

            $result = new Result($state, $stateReason, [
                new ResultMetric(ResultMetric::TYPE_GAUGE, 'total_pool_usage', $usedSpace)
            ]);
            $deferred->resolve($result);
        });

        return $deferred->promise();
    }

    /**
     * {@inheritDoc}
     */
    public function getProducableMetrics(array $attributes)
    {
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'total_pool_usage')
        ];
    }
}
