<?php
declare(strict_types=1);
require __DIR__.'/../wordpress-plugins/schuit-gedcom/includes/analyze.php';
require __DIR__.'/../wordpress-plugins/schuit-gedcom/includes/consistency.php';
$path=tempnam(sys_get_temp_dir(),'schuit-scale-');$f=fopen($path,'wb');
fwrite($f,"0 HEAD\n1 GEDC\n2 VERS 5.5.1\n");
for ($i=0;$i<100149;$i++) {
    fwrite($f,"0 @I$i@ INDI\n1 NAME Test$i /Voorbeeld/\n1 BIRT\n2 DATE 1 JAN 1800\n1 DEAT\n2 DATE 1 JAN 1870\n");
    if ($i<94110) { fwrite($f,'1 FAMS @F'.intdiv($i,2)."@\n"); }
}
for ($i=0;$i<47055;$i++) { fwrite($f,"0 @F$i@ FAM\n1 HUSB @I".($i*2)."@\n1 WIFE @I".($i*2+1)."@\n1 MARR\n2 DATE 1 JAN 1820\n"); }
fwrite($f,"0 TRLR\n");fclose($f);
try {
    $r=\Schuit\Gedcom\checkConsistency($path,'2026-10-29');
    if ($r['total']!==0||memory_get_peak_usage(true)>128*1024*1024) { throw new RuntimeException('Large-file consistency regression'); }
    echo "100,149 synthetic people / 47,055 families checked within 128 MiB; no false findings.\n";
} finally { unlink($path); }
