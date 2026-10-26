<?php
declare(strict_types=1);
require __DIR__ . '/../modules_v4/schuit-import/normalize.php';
$cases = [
    "1 NAME Jan/Schuit/\r\n" => "1 NAME Jan /Schuit /\r\n",
    "1 NAME Jan /Schuit/\n" => "1 NAME Jan /Schuit /\n",
    "1 NAME Zoë /Schuijt /\n" => "1 NAME Zoë /Schuijt /\n",
    "2 FILE media/portret.jpg\n" => "2 FILE media/portret.jpg\n",
    "1 WWW https://example.org/familie/\n" => "1 WWW https://example.org/familie/\n",
    "2 NOTE Zie /archief/\n" => "2 NOTE Zie /archief/\n",
];
foreach ($cases as $input => $expected) {
    $actual = schuit_normalize_gedcom_line($input);
    if ($actual !== $expected || schuit_normalize_gedcom_line($actual) !== $actual) { throw new RuntimeException('Normalization regression'); }
}
echo "GEDCOM normalization: six preservation/idempotence cases passed.\n";
