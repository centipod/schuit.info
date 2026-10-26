<?php
declare(strict_types=1);

/** Normalize only GEDCOM NAME delimiters; preserve URLs, paths and other fields. */
function schuit_normalize_gedcom_line(string $line): string {
    if (preg_match('/^\d+ NAME /', $line) !== 1) { return $line; }
    return (string) preg_replace('/(?<![ \t])\//', ' /', $line);
}
