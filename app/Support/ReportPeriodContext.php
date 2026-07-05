<?php

namespace App\Support;

class ReportPeriodContext
{
    /**
     * @param  int[]  $periodIds  Ordered ascending by start_date
     */
    public function __construct(
        public readonly array $periodIds,
        public readonly int $snapshotPeriodId,
        public readonly ?int $priorSnapshotPeriodId,
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly string $label,
        public readonly bool $isRange,
    ) {}

    public function isSinglePeriod(): bool
    {
        return count($this->periodIds) === 1;
    }

    public function primaryPeriodId(): int
    {
        return $this->snapshotPeriodId;
    }
}
