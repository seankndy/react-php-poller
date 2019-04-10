<?php
namespace SeanKndy\Poller\Results\Handlers;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric;
use React\EventLoop\LoopInterface;
use React\HttpClient\Client;
use React\HttpClient\Response;
use Psr\Log\LoggerInterface;
/**
 * Send incidents to alertmanager API
 */
class Alertmanager implements HandlerInterface
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
    private $alertmanagerApiUrl;


    public function __construct(LoopInterface $loop, LoggerInterface $logger,
        string $alertmanagerApiUrl)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->alertmanagerApiUrl = $alertmanagerApiUrl;
    }

    /**
     * {@inheritDoc}
     */
    public function process(Check $check, Result $result, Incident $newIncident = null)
    {
        $incident = $newIncident ? $newIncident : $check->getIncident();

        if ($incident && !$incident->isResolved()) {
            $meta = $check->getMeta();

            $portPathParts = explode('.', $meta['port_path']);
            if (count($portPathParts) == 4) {
                list($locationName, $subLocationName, $deviceName, $portName) =
                    $portPathParts;
                if ($subLocationName) {
                    $locationName = "$locationName.$subLocationName";
                }
            } else {
                list($locationName, $deviceName, $portName) =
                    $portPathParts;
            }

            $params = [
                'startsAt' => \date(DATE_ATOM, $result->getTime()),
                'endsAt' => \date(DATE_ATOM, $result->getTime()+($check->getInterval()*2)),
                'generatorURL' => $meta['url'],
                'labels' => [
                    'alertname' => $meta['port_path'].'.'.$meta['service_template'],
                    'devicename' => $deviceName,
                    'locationname' => $locationName,
                    'severity' => Result::stateIntToString($incident->getToState())
                ],
                'annotations' => [
                    'summary' => Result::stateIntToString($incident->getToState()) . ' // ' .
                            $meta['port_path'],
                    'description' => Result::stateIntToString($incident->getFromState()) . '->' .
                        Result::stateIntToString($incident->getToState()) . ' // ' .
                        $meta['port_path'] . '[' . $meta['port_desc'] . '] // ' .
                        $meta['service_template'] . ' // ' . $meta['ip'] . ' // ' .
                        \date('Y-m-d H:i:s', $result->getTime())
                ]
            ];
            return $this->httpPost([$params]);
        } else {
            return \React\Promise\resolve([]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function mutate(Check $check, Result $result, Incident $newIncident = null)
    {
        return \React\Promise\resolve([]);
    }

    /**
     * Post to alertmanager's API
     *
     */
    private function httpPost(array $params)
    {
        $deferred = new \React\Promise\Deferred();
        $client = new Client($this->loop);
        $jsonParams = \json_encode($params);
        $request = $client->request('POST', $this->alertmanagerApiUrl, [
            'Content-Type' => 'application/json',
            'Content-Length' => strlen($jsonParams)
        ]);
        $request->on('response', function (Response $response) use ($deferred) {
            if (substr($response->getCode(), 0, 1) != '2') {
                $deferred->reject(new \Exception("Non-2xx response code: " .
                    $response->getCode()));
                $response->close();
                return;
            }
            $respBody = '';
            $response->on('data', function ($chunk) use (&$respBody) {
                $respBody .= $chunk;
            });
            $response->on('end', function() use (&$respBody, $deferred) {
                $respData = \json_decode($respBody);
                if (isset($respData->status) && $respData->status == 'success') {
                    $deferred->resolve($respData);
                } else {
                    $deferred->reject(new \Exception("Failed to POST to alertmanager API"));
                }
            });
        });
        $request->on('error', function (\Throwable $e) use ($deferred) {
            $deferred->reject($e);
        });
        $request->end($jsonParams);
        return $deferred->promise();
    }
}
