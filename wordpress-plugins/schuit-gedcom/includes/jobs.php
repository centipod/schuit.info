<?php
declare(strict_types=1);

namespace Schuit\Gedcom;

const MAX_BYTES = 100 * 1024 * 1024;
const UNREVIEWED_STATES = ['uploaded', 'analyzing', 'ready'];

final class AnalysisStopped extends \RuntimeException {}

function root(): string {
    return getenv('SCHUIT_GEDCOM_JOBS') ?: '/var/lib/schuit-gedcom';
}

function directory(string $id): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new \RuntimeException('Ongeldige taak.'); }
    return root() . '/' . $id;
}

function read(string $id): array {
    $path = directory($id) . '/status.json';
    if (!is_file($path)) { throw new \RuntimeException('Taak niet gevonden.'); }
    return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
}

function write(array $job): void {
    $folder = directory($job['id']);
    $job['updated_at'] = gmdate('c');
    $data = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $temp = tempnam($folder, '.status-');
    if ($temp === false) { throw new \RuntimeException('Opslag niet beschikbaar.'); }
    try {
        chmod($temp, 0600);
        if (file_put_contents($temp, $data, LOCK_EX) !== strlen($data) || !rename($temp, $folder . '/status.json')) {
            throw new \RuntimeException('Status opslaan mislukt.');
        }
    } finally { if (is_file($temp)) { unlink($temp); } }
}

/** Serialize browser transitions against worker transitions. Never trust POST state. */
function change(string $id, callable $callback): array {
    $lock = fopen(directory($id) . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Taak is niet beschikbaar.'); }
    try {
        $current = read($id);
        $job = $callback($current);
        if ($job !== $current) { write($job); }
        return $job;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}

function jobs(): array {
    $jobs = [];
    foreach (glob(root() . '/*/status.json') ?: [] as $path) {
        try { $jobs[] = read(basename(dirname($path))); } catch (\Throwable) { /* Incomplete upload. */ }
    }
    usort($jobs, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return $jobs;
}

/** Caller holds queue.lock. All uploads use the one configured destination tree. */
function supersedeUnreviewed(array $replacement): void {
    foreach ($replacement['supersedes'] ?? [] as $id) {
        // A retained replacement can outlive an expired predecessor.
        if (!is_file(directory($id) . '/status.json')) { continue; }
        change($id, static function (array $job) use ($replacement): array {
            if (in_array($job['state'], UNREVIEWED_STATES, true)) {
                // Keep the existing terminal state understood by the host worker,
                // retention task and deployment gate; record why it was cancelled.
                $job['state'] = 'cancelled';
                $job['superseded_by'] = $replacement['id'];
                $job['superseded_at'] = $replacement['created_at'];
                $job['progress'] = 'Vervangen door een nieuwere upload. Dit bestand wordt niet geïmporteerd.';
            }
            return $job;
        });
    }
}

/** Lock order is queue -> job, including confirmation and analysis updates. */
function queueChange(callable $callback): mixed {
    $queue = fopen(root() . '/queue.lock', 'c');
    if (!$queue || !flock($queue, LOCK_EX)) { throw new \RuntimeException('De opslag is niet beschikbaar.'); }
    try {
        // The accepted upload contains the durable supersession decision. Finish
        // it after an interrupted request before any older job can be confirmed.
        foreach (jobs() as $replacement) { supersedeUnreviewed($replacement); }
        return $callback();
    } finally { flock($queue, LOCK_UN); fclose($queue); }
}

/** Store first: a rejected upload must not retire a usable predecessor. */
function enqueueUpload(int $owner, string $filename, int $bytes, callable $store): array {
    return queueChange(static function () use ($owner, $filename, $bytes, $store): array {
        $active = 0; $retainedBytes = 0; $supersedes = [];
        foreach (jobs() as $existing) {
            if (in_array($existing['state'], UNREVIEWED_STATES, true)) { $supersedes[] = $existing['id']; }
            elseif (!in_array($existing['state'], ['completed','failed','cancelled'], true)) { ++$active; }
            $retainedBytes += (int)($existing['bytes'] ?? 0);
        }
        if ($bytes < 1 || $bytes > MAX_BYTES) { throw new \RuntimeException('Kies een .ged-bestand van maximaal 100 MB.'); }
        if ($active >= 3 || $retainedBytes + $bytes > 500 * 1024 * 1024) {
            throw new \RuntimeException('De wachtrij of opslag is vol. Rond bevestigde taken af of vraag de beheerder om opslag vrij te maken.');
        }
        $id = bin2hex(random_bytes(16)); $folder = directory($id);
        if (!mkdir($folder, 0700)) { throw new \RuntimeException('Uploadmap aanmaken mislukt.'); }
        try {
            $store($folder . '/original.ged');
            if (!is_file($folder . '/original.ged') || filesize($folder . '/original.ged') !== $bytes) {
                throw new \RuntimeException('Upload bewaren mislukt.');
            }
            chmod($folder . '/original.ged', 0600);
            $job = ['id'=>$id, 'owner'=>$owner, 'filename'=>$filename, 'bytes'=>$bytes,
                'created_at'=>gmdate('c'), 'state'=>'uploaded', 'supersedes'=>$supersedes];
            write($job);
        } catch (\Throwable $e) {
            foreach (glob($folder . '/*') ?: [] as $path) { unlink($path); }
            rmdir($folder); throw $e;
        }
        supersedeUnreviewed($job);
        return $job;
    });
}

function reviewJob(string $id, bool $confirm, int $user): array {
    return queueChange(static fn(): array => change($id, static function (array $job) use ($confirm, $user): array {
        if ($job['state'] !== 'ready') { throw new \RuntimeException('Deze taak kan niet meer worden gewijzigd. Open de nieuwste upload.'); }
        $job['state'] = $confirm ? 'queued' : 'cancelled';
        $job['confirmed_by'] = $user; $job['confirmed_at'] = gmdate('c');
        return $job;
    }));
}

/** A worker holding an old snapshot must never resurrect a superseded upload. */
function saveAnalysis(array $job): void {
    queueChange(static function () use ($job): void {
        change($job['id'], static function (array $current) use ($job): array {
            if (!in_array($current['state'], ['uploaded','analyzing'], true)) { throw new AnalysisStopped(); }
            return $job;
        });
    });
}
