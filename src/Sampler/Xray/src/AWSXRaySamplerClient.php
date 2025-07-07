<?php
// src/Sampler/AWS/AWSXRaySamplerClient.php

namespace OpenTelemetry\Contrib\Sampler\Xray;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;

/**
 * A lightweight HTTP client for AWS X-Ray sampling endpoints.
 * Mirrors the .NET AWSXRaySamplerClient but without SigV4 or AWS SDK.
 */
class AWSXRaySamplerClient
{
    private HttpClient $httpClient;
    private string $host;

    /**
     * @param string $host
     *   The X-Ray service host (e.g. "xray.us-west-2.amazonaws.com").
     */
    public function __construct(string $host)
    {
        // Ensure no scheme is prepended; HttpClient will use HTTPS by default.
        $this->host       = rtrim($host, '/');
        $this->httpClient = new HttpClient([
            'base_uri' => $this->host,
            'timeout'  => 2.0,
        ]);
    }

    /**
     * Fetches all sampling rules from X-Ray by paging through NextToken.
     *
     * @return SamplingRule[]  Array of SamplingRule instances.
     * @throws GuzzleException on HTTP errors.
     */
    public function getSamplingRules(): array
    {
        $rules     = [];
        $response = $this->httpClient->post('GetSamplingRules');
        $data = json_decode((string) $response->getBody(), true);

        if (isset($data['SamplingRuleRecords'])) {
            foreach ($data['SamplingRuleRecords'] as $rec) {
                $r = $rec['SamplingRule'];
                $rules[] = new SamplingRule(
                    $r['RuleName'],
                    $r['Priority'],
                    $r['FixedRate'],
                    $r['ReservoirSize'],
                    $r['Host']        ?? '*',
                    $r['HTTPMethod']  ?? '*',
                    $r['ResourceARN'] ?? '*',
                    $r['ServiceName'] ?? '*',
                    $r['ServiceType'] ?? '*',
                    $r['URLPath']     ?? '*',
                    $r['Version']     ?? 1,
                    $r['Attributes']  ?? []
                );
            }
        }

        return $rules;
    }

    /**
     * Sends current statistics documents to X-Ray and returns the decoded response.
     *
     * @param SamplingStatisticsDocument[] $statistics
     * @return object|null  stdClass of the X-Ray GetSamplingTargets response.
     * @throws GuzzleException on HTTP errors.
     */
    public function getSamplingTargets(array $statistics): ?object
    {
        $docs = [];
        foreach ($statistics as $d) {
            $docs[] = [
                'ClientID'     => $d->ClientId,
                'RuleName'     => $d->RuleName,
                'RequestCount' => $d->RequestCount,
                'SampleCount'  => $d->SampleCount,
                'BorrowCount'  => $d->BorrowCount,
                'Timestamp'    => $d->Timestamp,
            ];
        }

        $response = $this->httpClient->post('SamplingTargets', [
            'json' => ['SamplingStatisticsDocuments' => $docs],
        ]);

        return json_decode((string) $response->getBody());
    }
}
