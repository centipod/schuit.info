<?php
declare(strict_types=1);
namespace Schuit\Gedcom;

/** Deterministic, advisory checks. Never updates a record or consults an external service. */
const CONSISTENCY_VERSION = '1.0.0';
const MAX_REPORT_ROWS = 500;

/** Conservative Gregorian date bounds; uncertainty is never converted to an exact date. */
function consistencyDate(string $value): array {
    $value = strtoupper(trim($value));
    $unknown = ['min'=>null, 'max'=>null, 'kind'=>'uncertain'];
    if ($value === '') { return $unknown; }
    if (preg_match('/^(ABT|CAL|EST|INT)\b|^@#D|\/|B\.C\.|^\(/', $value)) { return $unknown; }
    $atom = static function (string $text): ?array {
        if (!preg_match('/^(?:(\d{1,2}) )?(?:([A-Z]{3}) )?(\d{1,4})$/D', $text, $m)) { return null; }
        $months = array_flip(['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC']);
        $year = (int)$m[3]; $hasMonth = $m[2] !== ''; $hasDay = $m[1] !== '';
        if ($year < 1 || ($hasDay && !$hasMonth) || ($hasMonth && !isset($months[$m[2]]))) { return null; }
        $month = $hasMonth ? $months[$m[2]] + 1 : 1; $day = $hasDay ? (int)$m[1] : 1;
        if (!checkdate($month, $day, $year)) { return null; }
        $start = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day), new \DateTimeZone('UTC'));
        $end = $hasDay ? $start : ($hasMonth ? $start->modify('last day of this month') : $start->modify('last day of December'));
        return [(int)floor($start->getTimestamp()/86400), (int)floor($end->getTimestamp()/86400)];
    };
    if (preg_match('/^(BEF|AFT|FROM|TO) (.+)$/D', $value, $m) && !str_contains($m[2], ' TO ')) {
        $d = $atom($m[2]);
        if ($d === null) { return ['min'=>null,'max'=>null,'kind'=>'invalid']; }
        return ['min'=>in_array($m[1], ['AFT','FROM'], true) ? ($m[1] === 'AFT' ? $d[1] + 1 : $d[0]) : null,
            'max'=>in_array($m[1], ['BEF','TO'], true) ? ($m[1] === 'BEF' ? $d[0] - 1 : $d[1]) : null, 'kind'=>'range'];
    }
    if (preg_match('/^(?:BET (.+) AND (.+)|FROM (.+) TO (.+))$/D', $value, $m)) {
        $a = $atom($m[1] ?: $m[3]); $b = $atom($m[2] ?: $m[4]);
        return $a !== null && $b !== null && $a[0] <= $b[1] ? ['min'=>$a[0],'max'=>$b[1],'kind'=>'range'] : ['min'=>null,'max'=>null,'kind'=>'invalid'];
    }
    $d = $atom($value);
    return $d === null ? ['min'=>null,'max'=>null,'kind'=>'invalid'] : ['min'=>$d[0],'max'=>$d[1],'kind'=>$d[0] === $d[1] ? 'exact' : 'partial'];
}

function consistencyLabels(): array {
    return ['invalid_date'=>'Ongeldige datums', 'future_event'=>'Datums in de toekomst', 'death_before_birth'=>'Overlijden vóór geboorte',
        'event_order'=>'Doop of begrafenis in verkeerde volgorde', 'high_age'=>'Uitzonderlijk hoge leeftijd',
        'marriage_age'=>'Zeer jonge huwelijkspartner', 'marriage_after_death'=>'Huwelijk na overlijden', 'divorce_order'=>'Scheiding vóór huwelijk',
        'parent_age'=>'Opvallende leeftijd van ouder', 'posthumous_child'=>'Geboorte na overlijden ouder',
        'missing_reference'=>'Ontbrekende verwijzingen', 'reference_type'=>'Verwijzing naar verkeerd soort record',
        'reciprocal_link'=>'Onvolledige gezinskoppeling', 'self_relationship'=>'Persoon aan zichzelf gekoppeld',
        'ancestry_cycle'=>'Lus in afstamming', 'possible_duplicate'=>'Mogelijk dubbele personen',
        'duplicate_family'=>'Mogelijk dubbel huwelijk', 'name_delimiters'=>'Onduidelijke achternaamafbakening'];
}

final class ConsistencyCheck {
    // Store only names, relevant dates and relationships; no notes, contact details or media contents.
    private array $records = [];
    private array $currentRecord = [];
    private string $current = '';
    private string $event = '';
    private string $familyLink = '';
    private array $rows = [];
    private array $counts = [];
    private int $total = 0;
    private int $skipped = 0;
    private int $operations = 0;
    private float $started;
    private int $today;

    public function __construct(string $today) {
        $this->today = (int)(new \DateTimeImmutable($today, new \DateTimeZone('UTC')))->format('U');
        $this->today = (int)floor($this->today/86400); $this->started = microtime(true);
    }
    private function budget(): void {
        if (++$this->operations % 1000 === 0 && (memory_get_usage(true) > 280 * 1024 * 1024 || microtime(true) - $this->started > 180)) {
            throw new \RuntimeException('De consistentiecontrole overschrijdt de veilige verwerkingslimiet. Er is niets geïmporteerd. Vraag de beheerder om hulp.');
        }
    }
    public function consume(string $line): void {
        $this->budget();
        if (!preg_match('/^(\d+) (?:(@[^@]+@) )?([_A-Z0-9]+)(?: (.*))?$/D', $line, $m)) { return; }
        $level=(int)$m[1]; $tag=$m[3]; $value=$m[4] ?? '';
        if ($level===0) {
            $this->flush();
            $this->current=trim($m[2], '@'); $this->event=''; $this->familyLink='';
            if ($this->current!=='') { $this->currentRecord=[$tag,'',[],[],[]]; }
            return;
        }
        if ($this->current==='') { return; }
        $r=&$this->currentRecord;
        if ($level===1) {
            $this->event=''; $this->familyLink='';
            if ($tag==='NAME' && $r[1]==='') { $r[1]=mb_substr($value,0,240); }
            if (in_array($tag,['BIRT','DEAT','CHR','BAPM','BURI','CREM','MARR','DIV','MARL','ADOP'],true)) {
                $this->event=$tag; $r[2][$tag][]='';
            }
            if ($tag==='FAMC') { $this->familyLink=trim($value,'@'); }
        }
        if ($level===2 && $tag==='DATE' && $this->event!=='') {
            $index=count($r[2][$this->event])-1;
            // Multiple DATE children are ambiguous, never silently choose one.
            $r[2][$this->event][$index]=$r[2][$this->event][$index]==='' ? mb_substr($value,0,100) : '('.$r[2][$this->event][$index].' / '.mb_substr($value,0,100).')';
        }
        if ($level===2 && $tag==='FAMC' && $this->event==='ADOP') { $r[4][trim($value,'@')]='adopted'; }
        if ($level===2 && $tag==='PEDI' && $this->familyLink!=='') { $r[4][$this->familyLink]=strtolower(trim($value)); }
        if (preg_match('/^@([^@\s]+)@$/D',$value,$ref)) { $r[3][] = [$tag,$ref[1],$level]; }
    }
    private function flush(): void {
        if ($this->current!=='' && $this->currentRecord) {
            // Packed strings avoid PHP's large nested-array overhead on 100k+ people.
            $this->records[$this->current]=serialize($this->currentRecord);
        }
        $this->currentRecord=[];
    }
    private function record(string $id): array {
        return isset($this->records[$id]) ? unserialize($this->records[$id], ['allowed_classes'=>false]) : [];
    }
    private function name(string $id): string {
        return trim(preg_replace('/\s+/u',' ',str_replace('/',' ',$this->record($id)[1] ?? ''))) ?: $id;
    }
    private function links(string $id,string $tag): array {
        $out=[]; foreach ($this->record($id)[3] ?? [] as [$t,$ref,$level]) { if ($t===$tag && $level===1) { $out[]=$ref; } }
        return array_values(array_unique($out));
    }
    private function date(string $id,string $tag): array {
        $dates=$this->record($id)[2][$tag] ?? [];
        if (count($dates)!==1) { return ['min'=>null,'max'=>null,'kind'=>'uncertain','text'=>'onbekend of meerdere gebeurtenissen']; }
        return consistencyDate($dates[0])+['text'=>$dates[0] ?: 'onbekend'];
    }
    private function yearsAfter(int $day,int $years): int {
        $date=(new \DateTimeImmutable('@'.($day*86400)))->modify('+'.$years.' years');
        return (int)floor($date->getTimestamp()/86400);
    }
    private function after(array $a,array $b): bool { return $a['min']!==null && $b['max']!==null && $a['min']>$b['max']; }
    private function row(string $code,array $ids,string $finding,string $reason,string $solution): void {
        ++$this->total; $this->counts[$code]=($this->counts[$code] ?? 0)+1;
        if (count($this->rows)>=MAX_REPORT_ROWS) { return; }
        $names=[];
        foreach ($ids as $id) {
            if (($this->record($id)[0] ?? '')==='FAM') {
                foreach (array_merge($this->links($id,'HUSB'),$this->links($id,'WIFE')) as $partner) { $names[]=$this->name($partner); }
            } else { $names[]=$this->name($id); }
        }
        $this->rows[]=['code'=>$code, 'ids'=>array_slice($ids,0,12),
            'subject'=>mb_substr(implode(' / ',array_unique($names)),0,300), 'finding'=>$finding, 'reason'=>$reason, 'solution'=>$solution];
    }
    private function individuals(string $id,array $r): void {
        $b=$this->date($id,'BIRT'); $d=$this->date($id,'DEAT');
        if ($this->after($b,$d)) { $this->row('death_before_birth',[$id],"Geboorte: {$b['text']}. Overlijden: {$d['text']}.",'Overlijden ligt vóór geboorte.','Controleer geboorte- en overlijdensakte en de identiteit. Het juiste vervangende gegeven is niet automatisch vast te stellen.'); }
        elseif ($b['max']!==null && $d['min']!==null && $d['min']>$this->yearsAfter($b['max'],110)) {
            $this->row('high_age',[$id],"Geboorte: {$b['text']}. Overlijden: {$d['text']}.",'De levensduur is meer dan 110 jaar. Dit is een onderzoekssignaal, geen bewezen fout.','Verifieer beide datums en sluit verwisseling met een naamgenoot uit.');
        }
        foreach ([['BIRT','CHR'],['BIRT','BAPM'],['DEAT','BURI'],['DEAT','CREM']] as [$first,$second]) {
            $a=$this->date($id,$first);$z=$this->date($id,$second);
            if ($this->after($a,$z)) { $this->row('event_order',[$id],"$first: {$a['text']}; $second: {$z['text']}.",'Doop ligt vóór geboorte, of begrafenis/crematie vóór overlijden.','Controleer de originele akten, het gebeurtenistype en de gebruikte kalender.'); }
        }
        if (substr_count($r[1],'/')!==2 && str_contains($r[1],'/')) {
            $this->row('name_delimiters',[$id],'Naam: '.$r[1],'De achternaam is niet met één paar schuine strepen afgebakend.','Controleer naamvarianten en de achternaamvelden in het bronbestand.');
        }
    }
    private function family(string $id): void {
        $partners=array_unique(array_merge($this->links($id,'HUSB'),$this->links($id,'WIFE')));$children=$this->links($id,'CHIL');
        $m=$this->date($id,'MARR');$div=$this->date($id,'DIV');
        if ($this->after($m,$div)) { $this->row('divorce_order',[$id],"Huwelijk: {$m['text']}. Scheiding: {$div['text']}.",'Scheiding ligt vóór huwelijk binnen hetzelfde gezinsrecord.','Controleer de akten en of eerdere relaties of hertrouw apart moeten worden vastgelegd.'); }
        if (array_intersect($this->links($id,'HUSB'),$this->links($id,'WIFE'))) { $this->row('self_relationship',[$id],'Dezelfde persoon is beide partners in dit gezin.','Een persoon is als eigen partner gekoppeld.','Herstel de partnerkoppeling na controle van de bron.'); }
        foreach ($partners as $pid) {
            $this->budget();if (($this->record($pid)[0] ?? '')!=='INDI') { continue; }
            $b=$this->date($pid,'BIRT');$d=$this->date($pid,'DEAT');
            if ($b['min']!==null && $m['max']!==null && $m['max']<$this->yearsAfter($b['min'],12)) { $this->row('marriage_age',[$id,$pid],$this->name($pid).": geboorte {$b['text']}; huwelijk {$m['text']}.",'De partner is nog geen 12 jaar of nog niet geboren.','Controleer huwelijksdatum, geboortedatum en partneridentiteit.'); }
            if ($this->after($m,$d)) { $this->row('marriage_after_death',[$id,$pid],$this->name($pid).": overlijden {$d['text']}; huwelijk {$m['text']}.",'Huwelijk ligt na het overlijden van een partner.','Controleer huwelijks- en overlijdensakte en de partnerkoppeling.'); }
            foreach ($children as $cid) {
                $this->budget();if (($this->record($cid)[0] ?? '')!=='INDI') { continue; }
                if ($pid===$cid) { $this->row('self_relationship',[$id,$cid],'Persoon is zowel ouder als kind in dit gezin.','Een persoon kan niet de eigen ouder zijn.','Controleer en herstel de gezinskoppeling.');continue; }
                if (!in_array($this->record($cid)[4][$id] ?? '',['','birth'],true)) { continue; }
                $cb=$this->date($cid,'BIRT');$mother=in_array($pid,$this->links($id,'WIFE'),true);
                $young=$b['min']!==null && $cb['max']!==null && $cb['max']<$this->yearsAfter($b['min'],12);
                $old=$b['max']!==null && $cb['min']!==null && $cb['min']>$this->yearsAfter($b['max'],$mother?55:80);
                if ($young||$old) { $this->row('parent_age',[$id,$pid,$cid],$this->name($pid).": geboren {$b['text']}. Kind ".$this->name($cid).": geboren {$cb['text']}.",$young?'De gekoppelde ouder is jonger dan 12 jaar of nog niet geboren.':'De gekoppelde ouder is ouder dan '.($mother?'55':'80').' jaar; dit is geen bewezen fout.','Controleer datums, generaties en biologisch ouderschap; onderscheid adoptie en stiefouderschap.'); }
                if ($d['max']!==null && $cb['min']!==null && $cb['min']-$d['max']>($mother?3:366)) { $this->row('posthumous_child',[$id,$pid,$cid],$this->name($pid).": overleden {$d['text']}. Kind ".$this->name($cid).": geboren {$cb['text']}.",'De geboorte ligt te ver na het overlijden van de gekoppelde ouder om zonder meer bij biologisch ouderschap te passen.','Controleer datums en de ouder-kindkoppeling. Leg eventueel niet-biologisch ouderschap correct vast.'); }
            }
        }
    }
    private function references(string $id,array $r): void {
        $types=['FAMC'=>'FAM','FAMS'=>'FAM','HUSB'=>'INDI','WIFE'=>'INDI','CHIL'=>'INDI','SOUR'=>'SOUR','OBJE'=>'OBJE','NOTE'=>'NOTE','REPO'=>'REPO','SUBM'=>'SUBM'];
        foreach ($r[3] as [$tag,$target,$level]) {
            $this->budget();
            if (!isset($this->records[$target])) { $this->row('missing_reference',[$id],"$tag verwijst naar ontbrekend record $target.",'De koppeling heeft geen doel in dit bestand.','Herstel de verwijzing of voeg het ontbrekende record toe aan de export.');continue; }
            if (isset($types[$tag]) && $this->record($target)[0]!==$types[$tag]) { $this->row('reference_type',[$id],"$tag verwijst naar $target (".$this->record($target)[0].").",'De verwijzing wijst naar het verkeerde soort record.','Controleer de identificatie en het type van het doelrecord.');continue; }
            if ($level!==1) { continue; }
            $inverse=match($tag) {'FAMC'=>['CHIL'],'FAMS'=>['HUSB','WIFE'],'HUSB','WIFE'=>['FAMS'],'CHIL'=>['FAMC'],default=>[]};
            if (!$inverse) { continue; }
            $targets=[];foreach ($inverse as $back) { $targets=array_merge($targets,$this->links($target,$back)); }
            if (!in_array($id,$targets,true)) { $this->row('reciprocal_link',[$id,$target],"$id koppelt via $tag aan $target, maar de terugkoppeling ontbreekt.",'Persoons- en gezinsrecord beschrijven niet dezelfde relatie.','Controleer de relatie en herstel beide kanten van de koppeling in het bronbestand.'); }
        }
    }
    private function duplicates(): void {
        $seen=[];$couples=[];
        foreach ($this->records as $id=>$packed) {
            $id=(string)$id; $r=$this->record($id); $this->budget();
            if ($r[0]==='INDI') {
                $birth=$this->date($id,'BIRT');$given=trim(explode('/',$r[1])[0]);
                if ($birth['kind']!=='exact'||$given===''||preg_match('/levenlo|unknown|onbekend|naamloos|^\?$/iu',$given)) { continue; }
                $name=mb_strtolower(preg_replace('/\[[^\]]*\]|[\s\/]+/u',' ', $r[1]));$name=trim(preg_replace('/\s+/u',' ',$name));
                $key=$name.'|'.$birth['min'];
                if (isset($seen[$key])) { $this->row('possible_duplicate',[$seen[$key],$id],'Gelijke naam en exacte geboortedatum: '.$birth['text'].'.','Mogelijk dubbel ingevoerd of verwisseld. Naam en datum alleen bewijzen geen gelijke identiteit.','Vergelijk ouders, partners, plaatsen en akten. Niet automatisch samenvoegen.'); }
                else { $seen[$key]=$id; }
            } elseif ($r[0]==='FAM') {
                $p=array_unique(array_merge($this->links($id,'HUSB'),$this->links($id,'WIFE')));$m=$this->date($id,'MARR');
                if (count($p)!==2||$m['kind']!=='exact') { continue; }sort($p);$key=implode('|',$p).'|'.$m['min'];
                if (isset($couples[$key])) { $this->row('duplicate_family',[$couples[$key],$id],'Zelfde partners en dezelfde huwelijksdatum: '.$m['text'].'.','Mogelijk dubbel gezinsrecord. Hertrouw op een andere datum wordt niet als dubbel gemeld.','Vergelijk gebeurtenissen en kinderen voordat u eventueel samenvoegt.'); } else { $couples[$key]=$id; }
            }
        }
    }
    private function cycles(): void {
        // Iterative DFS avoids recursion limits; only explicitly biological/unspecified links.
        $colour=[];
        foreach ($this->records as $start=>$packed) {
            $start=(string)$start; $r=$this->record($start);
            if ($r[0]!=='INDI'||isset($colour[$start])) { continue; }
            $stack=[[$start,false]];
            while ($stack) {
                $this->budget();[$id,$exit]=array_pop($stack);
                if ($exit) { $colour[$id]=2;continue; }
                if (($colour[$id] ?? 0)===2) { continue; }
                if (($colour[$id] ?? 0)===1) { continue; }
                $colour[$id]=1;$stack[]=[$id,true];
                foreach ($this->links($id,'FAMC') as $fid) {
                    if (!in_array($this->record($id)[4][$fid] ?? '',['','birth'],true)) { continue; }
                    foreach (array_merge($this->links($fid,'HUSB'),$this->links($fid,'WIFE')) as $parent) {
                        if (($this->record($parent)[0] ?? '')!=='INDI'||$parent===$id) { continue; }
                        if (($colour[$parent] ?? 0)===1) { $this->row('ancestry_cycle',[$id,$parent],'Een ouderketen komt terug bij een eerdere persoon.','Een persoon wordt via meerdere generaties de eigen voorouder.','Controleer de ouderkoppelingen in deze familietak; herstel de foutieve relatie.'); }
                        elseif (!isset($colour[$parent])) { $stack[]=[$parent,false]; }
                    }
                }
            }
        }
    }
    public function result(): array {
        $this->flush();
        foreach ($this->records as $id=>$packed) {
            $id=(string)$id; $r=$this->record($id); $this->budget();$this->references((string)$id,$r);
            foreach ($r[2] as $tag=>$dates) {
                foreach ($dates as $text) {
                    if ($text==='') { continue; }$date=consistencyDate($text);
                    if ($date['kind']==='uncertain') { ++$this->skipped;continue; }
                    if ($date['kind']==='invalid') { $this->row('invalid_date',[(string)$id],"$tag: $text.",'Deze datum is ongeldig of niet als ondersteunde datum te lezen.','Controleer het brondocument en de GEDCOM-datumnotatie. Vul geen verzonnen datum in.'); }
                    elseif ($date['min']!==null && $date['min']>$this->today) { $this->row('future_event',[(string)$id],"$tag: $text.",'De gebeurtenis ligt na de controledatum.','Controleer het jaartal en of dit een geplande gebeurtenis is.'); }
                }
            }
            if ($r[0]==='INDI') { $this->individuals((string)$id,$r); }
            if ($r[0]==='FAM') { $this->family((string)$id); }
        }
        $this->cycles();$this->duplicates();
        return ['version'=>CONSISTENCY_VERSION,'checked_at'=>gmdate('c'),'total'=>$this->total,'counts'=>$this->counts,
            'reported'=>count($this->rows),'truncated'=>$this->total>count($this->rows),'uncertain_dates_skipped'=>$this->skipped,'rows'=>$this->rows];
    }
}

/** Input is the already validated UTF-8 normalized export. */
function checkConsistency(string $path, ?string $today=null): array {
    $check=new ConsistencyCheck($today ?? gmdate('Y-m-d'));$handle=fopen($path,'rb');
    if (!$handle) { throw new \RuntimeException('Consistentiecontrole kan het bestand niet openen.'); }
    try { foreach (lines($handle) as $line) { $check->consume($line); } } finally { fclose($handle); }
    return $check->result();
}
