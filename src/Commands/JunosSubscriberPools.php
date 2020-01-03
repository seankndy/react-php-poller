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
 * Uses MIB: https://www.juniper.net/documentation/en_US/junos/topics/reference/mibs/mib-jnx-subscriber.txt
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
    private $snmpWalkBin;
    /**
     * @var LoggerInterface
     */
    private $logger;

	public function __construct(LoopInterface $loop, LoggerInterface $logger,
        $snmpWalkBin = '/usr/bin/snmpwalk')
    {
        $this->loop = $loop;
        $this->logger = $logger;

        if (!\file_exists($snmpWalkBin)) {
            throw new \Exception("snmpwalk binary '$snmpWalkBin' not found!");
        }
        $this->snmpWalkBin = $snmpWalkBin;
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
            'cidr_pools' => '', // comma-separated list of address pools
            'warn_percent' => 97,
            'crit_percent' => 99
        ], $check->getAttributes());

        $deferred = new \React\Promise\Deferred();
        $stdoutBuffer = '';

        $command = "{$this->snmpWalkBin} -v 2c -c {$attributes['snmp_read_community']} " .
            "-OQ -Ov {$attributes['ip']} JUNIPER-SUBSCRIBER-MIB::jnxSubscriberIpAddress";
        $process = new \React\ChildProcess\Process($command);
        $process->start($this->loop);
        $process->stdout->on('data', function ($chunk) use (&$stdoutBuffer) {
            $stdoutBuffer .= $chunk;
        });
        $process->on('exit', function($exitCode, $termSignal) use ($deferred,
            $attributes, &$stdoutBuffer) {
            $ips = \preg_split('/[\r\n]+/', $stdoutBuffer);
            $cidrPools = \preg_split('/,\s*/', $attributes['cidr_pools']);

            $totalSpace = $usedSpace = 0;
            foreach ($cidrPools as $pool) {
                $totalSpace += $this->numIpsInNetwork($pool);
            }
            $this->logger->info(__CLASS__ . ": totalSpace=$totalSpace");

            foreach ($ips as $ip) {
                if ($ip == '0.0.0.0') {
                    continue;
                }
                foreach ($cidrPools as $pool) {
                    if ($this->ipInNetwork($ip, $pool)) {
                        $usedSpace++;
                    }
                }
            }
            $this->logger->info(__CLASS__ . ": usedSpace=$usedSpace");

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

    /**
     * Determine if IP address $ip is within network $network
     *
     * @param string $ip
     * @param string $network
     *
     * @return bool
     */
     private function ipInNetwork(string $ip, string $network)
     {
         [$subnet, $bits] = \explode('/', $network);
         if ($bits === null) {
             $bits = 32;
         }
         $ip = \ip2long($ip);
         $subnet = \ip2long($subnet);
         $mask = (-1 << (32 - $bits)) & \ip2long('255.255.255.255');
         $subnet &= $mask;
         return ($ip & $mask) == $subnet;
     }

     /**
      * Get number of IPs in network
      *
      * @param string $ip
      * @param string $network
      *
      * @return bool
      */
      private function numIpsInNetwork(string $network)
      {
          [$subnet, $bits] = \explode('/', $network);
          return \pow(2, (32-$bits));
      }
}
