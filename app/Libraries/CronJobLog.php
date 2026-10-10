<?php

namespace App\Libraries;

use Config\Database;

/**
 * Records scheduled-task run outcomes into the task's history.
 *
 * A maintenance command invoked by cron leaves nothing behind: it runs, prints
 * to a console nobody is watching, and exits. The only place an operator can see
 * what a recurring task has been doing is the `cron_jobs` row, and a single
 * summary line says whether a task *ran* but not whether it keeps *needing* to
 * run. A PPPoE link that flaps every hour is invisible if each run merely
 * overwrites the previous summary.
 *
 * So this keeps a bounded history of the events that mattered, newest first, in
 * the task's `last_output` — the same field the Cron page already renders
 * through its Log button. Recurring repairs become a visible pattern rather than
 * something that has to be reconstructed from a log aggregator.
 *
 * Two deliberate constraints:
 *
 *  - The text is capped. `last_output` is a TEXT column rendered into a browser
 *    modal, and an unbounded append would grow fastest in exactly the failure
 *    case it is meant to reveal.
 *  - The history is plain ASCII with a line-leading timestamp, so it stays
 *    legible in the modal and greppable in a database dump.
 */
class CronJobLog
{
    /** The PPPoE redial task, which keeps its own history. */
    public const PPPOE_REDIAL_JOB = 'cron_pppoe_redial';

    /** The curated-repository drift sweep, which keeps its own history. */
    public const REPO_DRIFT_JOB = 'cron_plugin_repo_drift';

    /** The ACME certificate renewal sweep, which keeps its own history. */
    public const ACME_RENEW_JOB = 'cron_acme_renew';

    /**
     * Scheduled tasks that keep a history rather than a single summary line.
     *
     * A manual run from the Cron page must not overwrite these the way it
     * overwrites an ordinary task's output: the value of the history is the
     * pattern across runs, and replacing it with one blob erases exactly that.
     *
     * @var list<string>
     */
    public const HISTORY_JOBS = [self::PPPOE_REDIAL_JOB, self::REPO_DRIFT_JOB, self::ACME_RENEW_JOB];

    /** Whether a scheduled task keeps its own run history. */
    public static function keepsHistory(string $jobId): bool
    {
        return in_array($jobId, self::HISTORY_JOBS, true);
    }

    /** How many past events to keep. */
    public const DEFAULT_KEEP = 20;

    /** Upper bound on the stored text, in bytes. */
    public const MAX_BYTES = 20000;

    /** Marks a timestamped history entry. */
    public const ENTRY_PREFIX = '[';

    private string $jobId;

    private int $keep;

    /** @var \CodeIgniter\Database\BaseConnection */
    private $db;

    /**
     * @param string                                    $jobId the `cron_jobs.id` to record against
     * @param int|null                                  $keep  events to retain
     * @param \CodeIgniter\Database\BaseConnection|null $db    connection, for tests
     */
    public function __construct(string $jobId, ?int $keep = null, $db = null)
    {
        $this->jobId = $jobId;
        $this->keep  = $keep !== null && $keep > 0 ? $keep : self::DEFAULT_KEEP;
        $this->db    = $db ?? Database::connect();
    }

    /**
     * Whether the scheduled task exists to record against.
     *
     * A command can be run by hand before its cron row is seeded, and a failure
     * to record must never stop the command doing its real work.
     */
    public function exists(): bool
    {
        try {
            return $this->db->table('cron_jobs')->where('id', $this->jobId)->countAllResults() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Prepends one event to the task's history and refreshes the summary columns.
     *
     * @param string $summary one line describing what this run did
     * @param string $status  'success' or 'error' (drives the badge colour)
     * @param string $detail  optional extra lines, indented under the entry
     *
     * @return bool false when the task row is missing or the write failed
     */
    public function record(string $summary, string $status = 'success', string $detail = ''): bool
    {
        $summary = $this->oneLine($summary);
        $status  = in_array($status, ['success', 'error'], true) ? $status : 'error';

        if ($summary === '') {
            return false;
        }

        try {
            $row = $this->db->table('cron_jobs')->where('id', $this->jobId)->get()->getRowArray();
        } catch (\Throwable $e) {
            return false;
        }

        if (! $row) {
            return false;
        }

        $entries = $this->parse((string) ($row['last_output'] ?? ''));

        // Newest first, so the newest event is the first thing the modal shows.
        array_unshift($entries, [
            'at'      => date('Y-m-d H:i:s'),
            'status'  => $status,
            'summary' => $summary,
            'detail'  => $this->normaliseDetail($detail),
        ]);

        $entries = array_slice($entries, 0, $this->keep);
        $now     = date('Y-m-d H:i:s');

        try {
            $this->db->table('cron_jobs')->where('id', $this->jobId)->update([
                'last_run_at' => $now,
                'last_status' => $status,
                'last_output' => $this->render($entries),
                'updated_at'  => $now,
            ]);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * The stored history, newest first.
     *
     * @return array<int,array{at:string,status:string,summary:string,detail:string}>
     */
    public function history(): array
    {
        try {
            $row = $this->db->table('cron_jobs')->where('id', $this->jobId)->get()->getRowArray();
        } catch (\Throwable $e) {
            return [];
        }

        return $row ? $this->parse((string) ($row['last_output'] ?? '')) : [];
    }

    /**
     * How many retained events repaired a link.
     *
     * This is the flap signal: a link that needs rebuilding run after run shows
     * up here even though every individual run reports success. Five repairs in
     * twenty runs means the link is not staying up, which no single run reveals.
     */
    public function repairsInHistory(): int
    {
        $count = 0;
        foreach ($this->history() as $entry) {
            if (preg_match('/(\d+)\s+repaired/', $entry['summary'], $m) && (int) $m[1] > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Parse stored text back into entries.
     *
     * A line beginning with `[` starts an entry; every following line that does
     * not begin with `[` belongs to it. Detail lines are indented, so they can
     * never be mistaken for a new entry.
     *
     * @return array<int,array{at:string,status:string,summary:string,detail:string}>
     */
    private function parse(string $text): array
    {
        $entries = [];
        $current = null;

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (str_starts_with($line, self::ENTRY_PREFIX)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                // [2026-10-03 17:51:12] SUCCESS - summary
                if (preg_match('/^\[([^\]]+)\]\s+(\w+)\s*-\s*(.*)$/', $line, $m)) {
                    $current = [
                        'at'      => $m[1],
                        'status'  => strtolower($m[2]),
                        'summary' => $m[3],
                        'detail'  => '',
                    ];
                } else {
                    $current = ['at' => '', 'status' => '', 'summary' => trim($line), 'detail' => ''];
                }

                continue;
            }

            if ($current !== null && trim($line) !== '') {
                $current['detail'] .= ($current['detail'] === '' ? '' : "\n") . rtrim($line);
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * Render entries newest-first into the stored text, capped by size.
     *
     * @param array<int,array{at:string,status:string,summary:string,detail:string}> $entries
     */
    private function render(array $entries): string
    {
        $repairs = 0;
        foreach ($entries as $entry) {
            if (preg_match('/(\d+)\s+repaired/', $entry['summary'], $m) && (int) $m[1] > 0) {
                $repairs++;
            }
        }

        $blocks = [sprintf(
            'MicronOS scheduled task history - %d event(s), %d of which repaired a link. Most recent first.',
            count($entries),
            $repairs,
        )];

        foreach ($entries as $entry) {
            $block = sprintf(
                '%s%s] %s - %s',
                self::ENTRY_PREFIX,
                $entry['at'],
                strtoupper($entry['status']),
                $entry['summary'],
            );

            if ($entry['detail'] !== '') {
                $block .= "\n" . $entry['detail'];
            }

            $blocks[] = $block;
        }

        $text = implode("\n\n", $blocks);

        // The cap keeps the newest events, so a truncation drops the oldest.
        if (strlen($text) > self::MAX_BYTES) {
            $text = substr($text, 0, self::MAX_BYTES);
            $text .= "\n\n[history truncated]";
        }

        return $text;
    }

    /** Collapse a value to a single line, so it cannot forge a history entry. */
    private function oneLine(string $value): string
    {
        $value = preg_replace('/[\r\n\t]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s{2,}/', ' ', $value) ?? $value);
    }

    /** Indent detail lines so they can never be read as an entry. */
    private function normaliseDetail(string $detail): string
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $detail) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = '  ' . $line;
            }
        }

        return implode("\n", $lines);
    }
}
