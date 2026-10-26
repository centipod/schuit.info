<?php
declare(strict_types=1);

namespace Schuit\Gedcom;

const MAX_BYTES = 100 * 1024 * 1024;

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
    $temp = tempnam($folder, '.status-');
    if ($temp === false) { throw new \RuntimeException('Opslag niet beschikbaar.'); }
    chmod($temp, 0600);
    file_put_contents($temp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX);
    if (!rename($temp, $folder . '/status.json')) { throw new \RuntimeException('Status opslaan mislukt.'); }
}

/** Serialize browser transitions against worker transitions. Never trust POST state. */
function change(string $id, callable $callback): array {
    $lock = fopen(directory($id) . '/lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new \RuntimeException('Taak is niet beschikbaar.'); }
    try {
        $job = $callback(read($id));
        write($job);
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
