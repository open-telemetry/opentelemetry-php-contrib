<?php
// src/Sampler/AWS/SamplingStatisticsDocument.php
namespace OpenTelemetry\Contrib\Sampler\Xray;

class SamplingStatisticsDocument
{
    public string $ClientId;
    public string $RuleName;
    public int    $RequestCount;
    public int    $SampleCount;
    public int    $BorrowCount;
    public float  $Timestamp;
    
    public function __construct(string $clientId, string $ruleName, int $req, int $samp, int $borrow, float $ts)
    {
        $this->ClientId     = $clientId;
        $this->RuleName     = $ruleName;
        $this->RequestCount = $req;
        $this->SampleCount  = $samp;
        $this->BorrowCount  = $borrow;
        $this->Timestamp    = $ts;
    }
}
