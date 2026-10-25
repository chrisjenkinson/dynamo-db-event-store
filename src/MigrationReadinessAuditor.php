<?php

declare(strict_types=1);

namespace chrisjenkinson\DynamoDbEventStore;

use PDO;
use RuntimeException;

/**
 * Exact, bounded-memory reconciliation of legacy event rows and their GSI
 * projections. Requires pdo_sqlite only when auditing (not for normal use).
 *
 * Run exclusively against a quiesced source table. A strong Scan is not a
 * snapshot while writes are ongoing; the replay GSI is eventually consistent.
 */
final class MigrationReadinessAuditor
{
    /** @var array<string, int> */
    private array $issueCounts = [];

    /** @var array<string, list<string>> */
    private array $examples = [];

    public function __construct(
        private readonly MigrationInventory $inventory,
        private readonly int $sampleLimit = 10
    ) {
    }

    public function audit(?string $workingDirectory = null, int $pageSize = 100): MigrationReadinessReport
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Migration readiness auditing requires the pdo_sqlite extension.');
        }

        if ($this->sampleLimit < 0) {
            throw new \InvalidArgumentException('Sample limit cannot be negative.');
        }

        $file = tempnam($workingDirectory ?? sys_get_temp_dir(), 'broadway-audit-');

        if ($file === false) {
            throw new RuntimeException('Could not create a temporary migration audit database.');
        }

        $this->issueCounts = [];
        $this->examples = [];

        try {
            $db = new PDO('sqlite:' . $file);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->exec('CREATE TABLE events (
                aggregate_id TEXT NOT NULL,
                playhead INTEGER NOT NULL,
                global_position INTEGER NULL,
                fingerprint TEXT NOT NULL,
                seen INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (aggregate_id, playhead)
            )');

            $db->beginTransaction();

            try {
                $insert = $db->prepare('INSERT INTO events (aggregate_id, playhead, global_position, fingerprint)
                    VALUES (:id, :playhead, :position, :fingerprint)');
                $sourceCount = 0;
                $cursor = null;

                do {
                    $page = $this->inventory->sourcePage($cursor, $pageSize);

                    foreach ($page->events() as $event) {
                        ++$sourceCount;

                        $insert->execute([
                            ':id' => $event->aggregateId,
                            ':playhead' => $event->playhead,
                            ':position' => $event->globalPosition,
                            ':fingerprint' => $event->fingerprint(),
                        ]);

                        if ($event->globalPosition === null) {
                            $this->issue('missing_global_position', $event->identity());
                        }

                        if ($event->feed !== 'all') {
                            $this->issue('missing_replay_feed', $event->identity());
                        }
                    }

                    $cursor = $page->nextCursor();
                } while ($cursor !== null);

                $db->commit();
            } catch (\Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                throw $exception;
            }

            $this->inspectSourceSequences($db);

            $lookup = $db->prepare('SELECT global_position, fingerprint, seen FROM events
                WHERE aggregate_id = :id AND playhead = :playhead');
            $markSeen = $db->prepare('UPDATE events SET seen = 1
                WHERE aggregate_id = :id AND playhead = :playhead');

            $replayCount = 0;
            $lastPosition = null;
            $cursor = null;

            do {
                $page = $this->inventory->replayPage($cursor, $pageSize);

                foreach ($page->events() as $event) {
                    ++$replayCount;

                    if ($event->globalPosition === null || ($lastPosition !== null && $event->globalPosition <= $lastPosition)) {
                        $this->issue('replay_order', $event->identity());
                    }

                    $lastPosition = $event->globalPosition;

                    $lookup->execute([':id' => $event->aggregateId, ':playhead' => $event->playhead]);
                    $source = $lookup->fetch(PDO::FETCH_ASSOC);

                    if ($source === false) {
                        $this->issue('orphan_replay_event', $event->identity());
                        continue;
                    }

                    if ((int) $source['seen'] === 1) {
                        $this->issue('duplicate_replay_event', $event->identity());
                    }

                    $markSeen->execute([':id' => $event->aggregateId, ':playhead' => $event->playhead]);

                    $sourcePosition = $source['global_position'] === null ? null : (int) $source['global_position'];

                    if ($sourcePosition !== $event->globalPosition || $source['fingerprint'] !== $event->fingerprint()) {
                        $this->issue('replay_data_mismatch', $event->identity());
                    }
                }

                $cursor = $page->nextCursor();
            } while ($cursor !== null);

            $missing = $db->query('SELECT aggregate_id, playhead FROM events WHERE seen = 0');

            if ($missing === false) {
                throw new RuntimeException('Could not enumerate missing replay events.');
            }

            while (($row = $missing->fetch(PDO::FETCH_ASSOC)) !== false) {
                $this->issue('missing_replay_event', sprintf('%s (playhead %d)', $row['aggregate_id'], $row['playhead']));
            }

            return new MigrationReadinessReport($sourceCount, $replayCount, $this->issueCounts, $this->examples);
        } finally {
            unset($db);
            @unlink($file);
        }
    }

    private function inspectSourceSequences(PDO $db): void
    {
        $duplicates = $db->query('SELECT global_position, COUNT(*) AS total FROM events
            WHERE global_position IS NOT NULL
            GROUP BY global_position HAVING COUNT(*) > 1');

        if ($duplicates === false) {
            throw new RuntimeException('Could not check duplicate global positions.');
        }

        while (($row = $duplicates->fetch(PDO::FETCH_ASSOC)) !== false) {
            $this->issue('duplicate_global_position', sprintf(
                'Position %d belongs to %d source events',
                $row['global_position'],
                $row['total']
            ));
        }

        $rows = $db->query('SELECT aggregate_id, playhead FROM events ORDER BY aggregate_id, playhead');

        if ($rows === false) {
            throw new RuntimeException('Could not check aggregate playheads.');
        }

        $previousId = null;
        $previousPlayhead = null;

        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (string) $row['aggregate_id'];
            $playhead = (int) $row['playhead'];
            $expected = $id === $previousId ? $previousPlayhead + 1 : 0;

            if ($playhead !== $expected) {
                $this->issue('playhead_gap', sprintf('%s: expected %d, found %d', $id, $expected, $playhead));
            }

            $previousId = $id;
            $previousPlayhead = $playhead;
        }
    }

    private function issue(string $type, string $description): void
    {
        $this->issueCounts[$type] = ($this->issueCounts[$type] ?? 0) + 1;

        if (count($this->examples[$type] ?? []) < $this->sampleLimit) {
            $this->examples[$type][] = $description;
        }
    }
}
