<?php
declare(strict_types=1);
require __DIR__.'/../wordpress-plugins/schuit-gedcom/includes/analyze.php';
require __DIR__.'/../wordpress-plugins/schuit-gedcom/includes/consistency.php';
use function Schuit\Gedcom\{consistencyDate,checkConsistency};
function ok(bool $condition,string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function review(string $body): array {
    $p=tempnam(sys_get_temp_dir(),'check-'); file_put_contents($p,"0 HEAD\n1 GEDC\n2 VERS 5.5.1\n".$body."\n0 TRLR\n");
    $before=hash_file('sha256',$p);
    try { $r=checkConsistency($p,'2026-10-29');ok(hash_file('sha256',$p)===$before,'Read-only analysis');return $r; }
    finally { unlink($p); }
}
function codes(array $r): array { return $r['counts']; }
ok(consistencyDate('29 FEB 1900')['kind']==='invalid','Non leap year');
ok(consistencyDate('29 FEB 2000')['kind']==='exact','Leap year');
ok(consistencyDate('0')['kind']==='invalid','Zero year');
foreach (['ABT 1900','CAL 1900','EST 1900','INT 1900 (bron)','@#DJULIAN@ 1 JAN 1700','1731/32','(onbekend)'] as $d) { ok(consistencyDate($d)['kind']==='uncertain','Uncertain '.$d); }
$d=consistencyDate('1900');ok($d['min']===consistencyDate('1 JAN 1900')['min']&&$d['max']===consistencyDate('31 DEC 1900')['max'],'Year interval');
ok(consistencyDate('AFT 1900')['min']===consistencyDate('1 JAN 1901')['min'],'After whole year');
ok(consistencyDate('BEF 1900')['max']===consistencyDate('31 DEC 1899')['max'],'Before whole year');
ok(consistencyDate('FROM 1900 TO 1902')['max']===consistencyDate('31 DEC 1902')['max'],'From/to');
ok(consistencyDate('BET 1902 AND 1900')['kind']==='invalid','Reversed range');
$r=review("0 @1@ INDI\n1 NAME Aná /Voorbeeld/\n1 BIRT\n2 DATE 1 JAN 1900\n1 DEAT\n2 DATE 1 JAN 1899\n1 CHR\n2 DATE 1898\n0 @2@ INDI\n1 NAME Aná /Voorbeeld/\n1 BIRT\n2 DATE 1 JAN 1900\n0 @3@ INDI\n1 BIRT\n2 DATE 31 FEB 1900\n1 DEAT\n2 DATE 1 JAN 2100");
foreach (['death_before_birth','event_order','possible_duplicate','invalid_date','future_event'] as $c) { ok(isset($r['counts'][$c]),'Expected '.$c); }
ok(str_contains($r['rows'][0]['subject'],'Aná'),'Unicode names');
$r=review("0 @I@ INDI\n1 NAME Test /Voorbeeld/\n1 BIRT\n2 DATE BET 1900 AND 1902\n1 DEAT\n2 DATE 1901");ok($r['total']===0,'Overlapping ranges are not conflicts');
$r=review("0 @I@ INDI\n1 BIRT\n2 DATE ABT 1900\n1 DEAT\n2 DATE 1899");ok($r['total']===0&&$r['uncertain_dates_skipped']===1,'Approximate dates skipped');
$r=review("0 @I@ INDI\n1 BIRT\n2 DATE 1 MAR 1900\n1 DEAT\n2 DATE 1 MAR 2010");ok(!isset($r['counts']['high_age']),'Exact 110th birthday');
$r=review("0 @I@ INDI\n1 BIRT\n2 DATE 1 MAR 1900\n1 DEAT\n2 DATE 2 MAR 2010");ok(isset($r['counts']['high_age']),'Over 110');
$family="0 @P@ INDI\n1 NAME Ouder /Voorbeeld/\n1 BIRT\n2 DATE 1 JAN 1900\n1 DEAT\n2 DATE 1 JAN 1908\n1 FAMS @F@\n0 @C@ INDI\n1 NAME Kind /Voorbeeld/\n1 BIRT\n2 DATE 1 JAN 1910\n1 FAMC @F@\n%s\n0 @F@ FAM\n1 WIFE @P@\n1 CHIL @C@\n1 MARR\n2 DATE 1 JAN 1909\n1 DIV\n2 DATE 1 JAN 1907";
$r=review(sprintf($family,''));foreach (['parent_age','posthumous_child','marriage_age','marriage_after_death','divorce_order'] as $c) { ok(isset($r['counts'][$c]),$c); }
foreach (["2 PEDI adopted","1 ADOP\n2 FAMC @F@"] as $pedigree) { $r=review(sprintf($family,$pedigree));ok(!isset($r['counts']['parent_age'])&&!isset($r['counts']['posthumous_child']),'Adoption excluded'); }
$r=review("0 @I@ INDI\n1 FAMC @MISSING@\n1 FAMS @OTHER@\n0 @OTHER@ INDI\n0 @F@ FAM\n1 HUSB @I@");foreach (['missing_reference','reference_type','reciprocal_link'] as $c) { ok(isset($r['counts'][$c]),$c); }
$r=review("0 @I@ INDI\n1 FAMS @F@\n1 FAMC @F@\n0 @F@ FAM\n1 HUSB @I@\n1 WIFE @I@\n1 CHIL @I@");ok(isset($r['counts']['self_relationship']),'Self relationships');
$r=review("0 @1@ INDI\n1 FAMC @F1@\n1 FAMS @F2@\n0 @2@ INDI\n1 FAMC @F2@\n1 FAMS @F1@\n0 @F1@ FAM\n1 HUSB @2@\n1 CHIL @1@\n0 @F2@ FAM\n1 HUSB @1@\n1 CHIL @2@");ok(isset($r['counts']['ancestry_cycle']),'Two generation cycle numeric IDs');
$r=review("0 @I1@ INDI\n1 NAME /Voorbeeld/\n1 BIRT\n2 DATE 1 JAN 1900\n0 @I2@ INDI\n1 NAME /Voorbeeld/\n1 BIRT\n2 DATE 1 JAN 1900");ok(!isset($r['counts']['possible_duplicate']),'Unnamed twins not duplicates');
$r=review("0 @I@ INDI\n1 NAME Test /Naam/ /Extra/\n1 BIRT\n2 DATE 1900\n1 BIRT\n2 DATE 1902\n1 DEAT\n2 DATE 1890");ok(isset($r['counts']['name_delimiters'])&&!isset($r['counts']['death_before_birth']),'Ambiguous repeated birth not chosen');
$couples="0 @P@ INDI\n1 FAMS @F1@\n1 FAMS @F2@\n0 @Q@ INDI\n1 FAMS @F1@\n1 FAMS @F2@\n0 @F1@ FAM\n1 HUSB @P@\n1 WIFE @Q@\n1 MARR\n2 DATE 1 JAN 1900\n0 @F2@ FAM\n1 HUSB @P@\n1 WIFE @Q@\n1 MARR\n2 DATE %s";
ok(isset(review(sprintf($couples,'1 JAN 1900'))['counts']['duplicate_family']),'Same marriage possibly duplicate');
ok(!isset(review(sprintf($couples,'1 JAN 1910'))['counts']['duplicate_family']),'Remarriage not duplicate');
$many='';for ($i=0;$i<510;$i++) { $many.="0 @I$i@ INDI\n1 BIRT\n2 DATE 31 FEB 1900\n"; }
$r=review($many);ok($r['total']===510&&$r['reported']===500&&$r['truncated'],'Bounded report has accurate total');
echo "Consistency date, relationship, privacy and bounded-report checks passed.\n";
