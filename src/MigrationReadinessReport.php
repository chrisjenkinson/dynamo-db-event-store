<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

final class MigrationReadinessReport
{
    /**
     * @param array<string, int> $issueCounts
     * @param array<string, list<string>> $examples
     */
    public function __construct(
        public readonly int $sourceEvents,
        public readonly int $replayEvents,
        public readonly array $issueCounts,
        public readonly array $examples
    ) {
    }

    public function ready(): bool
    {
        return $this->issueCounts === [];
    }

    /**
     * @return array{ready: bool, source_events: int, replay_events: int, issues: array<string, int>, examples: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready(),
            'source_events' => $this->sourceEvents,
            'replay_events' => $this->replayEvents,
            'issues' => $this->issueCounts,
            'examples' => $this->examples,
        ];
    }
}
