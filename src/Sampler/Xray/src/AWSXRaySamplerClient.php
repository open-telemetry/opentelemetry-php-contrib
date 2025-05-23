<?php
// src/Sampler/AWS/AWSXRaySamplerClient.php
namespace OpenTelemetry\Contrib\Sampler\Xray;

use Aws\XRay\XRayClient;

class AWSXRaySamplerClient
{
    private XRayClient $client;
    
    public function __construct(array $config)
    {
        $this->client = new XRayClient($config);
    }
    
    /** @return SamplingRule[] */
    public function getSamplingRules(): array
    {
        $out = [];
        $p = $this->client->getPaginator('GetSamplingRules');
        foreach ($p as $page) {
            foreach ($page['SamplingRuleRecords'] as $rec) {
                $r = $rec['SamplingRule'];
                $out[] = new SamplingRule(
                    $r['RuleName'],
                    $r['Priority'],
                    $r['FixedRate'],
                    $r['ReservoirSize'],
                    $r['Host'] ?? '*',
                    $r['HTTPMethod'] ?? '*',
                    $r['ResourceARN'] ?? '*',
                    $r['ServiceName'] ?? '*',
                    $r['ServiceType'] ?? '*',
                    $r['URLPath'] ?? '*',
                    $r['Version'] ?? 1,
                    $r['Attributes'] ?? []
                );
            }
        }
        return $out;
    }
    
    /** @return object|null  – raw SamplingTargets response */
    public function getSamplingTargets(array $statistics): ?array
    {
        $resp = $this->client->getSamplingTargets([
            'SamplingStatisticsDocuments' => $statistics,
        ]);
        return $resp->toArray();
    }
}
