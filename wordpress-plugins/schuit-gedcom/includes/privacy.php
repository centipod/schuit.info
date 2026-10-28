<?php
declare(strict_types=1);
namespace Schuit\Gedcom;

/** Root-managed exclusion IDs are outside the web-writable queue and application data. */
function exclusions(?string $path = null): array {
    $path ??= '/run/schuit-privacy/excluded-xrefs.json';
    if (!is_file($path)) { throw new \RuntimeException('Het privacyregister is niet beschikbaar. Neem contact op met de beheerder.'); }
    $raw = file_get_contents($path, false, null, 0, 262145);
    if ($raw === false || strlen($raw) > 262144) { throw new \RuntimeException('Ongeldig privacyregister.'); }
    $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['excluded_xrefs'] ?? null)) { throw new \RuntimeException('Ongeldig privacyregister.'); }
    $result = [];
    foreach ($data['excluded_xrefs'] as $id) {
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9:_.-]{1,20}$/D', $id)) { throw new \RuntimeException('Ongeldig privacyregister.'); }
        $result[$id] = true;
    }
    return $result;
}
function assertNotExcluded(string $id, array $excluded): void {
    if (isset($excluded[$id])) { throw new \RuntimeException('Het bestand bevat een record dat volgens het privacyregister niet opnieuw mag worden geïmporteerd. Corrigeer het bronbestand en neem contact op met de beheerder.'); }
}
function verifyPrivacyFile(string $path): void {
    $excluded = exclusions();
    $stream = fopen($path, 'rb');
    if (!$stream) { throw new \RuntimeException('Bestand niet beschikbaar.'); }
    try {
        foreach (lines($stream) as $line) {
            if (preg_match('/^0 @([^@]+)@ /', $line, $match)) { assertNotExcluded($match[1], $excluded); }
        }
    } finally { fclose($stream); }
}
