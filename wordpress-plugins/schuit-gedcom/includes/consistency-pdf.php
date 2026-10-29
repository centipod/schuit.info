<?php
declare(strict_types=1);
namespace Schuit\Gedcom;

/** Uses webtrees' already pinned TCPDF; no additional renderer or network requests. */
final class ConsistencyPdf extends \TCPDF {
    public string $reviewDate = '';
    public function Header(): void {
        $this->SetFillColor(15,76,117);$this->Rect(0,0,297,2,'F');
        $this->Image(dirname(__DIR__).'/assets/centipod-logo.png',12,8,39,0,'PNG');
        $this->SetTextColor(15,76,117);$this->SetFont('dejavusans','B',13);
        $this->SetXY(59,8);$this->Cell(225,7,'Schuit.info | Controle van stamboomgegevens');
        $this->SetFont('dejavusans','',8);$this->SetTextColor(84,110,122);
        $this->SetXY(59,16);$this->Cell(220,4,'Automatische signalen ter beoordeling - geen gegevens gewijzigd');
        $this->SetDrawColor(217,118,66);$this->Line(12,23,285,23);
    }
    public function Footer(): void {
        $this->SetY(-11);$this->SetFont('dejavusans','',7);$this->SetTextColor(84,110,122);
        $this->Cell(210,4,'Controle '.$this->reviewDate.' | Privé: alleen delen met bevoegde beoordelaars');
        $this->Cell(63,4,$this->getAliasNumPage().' / '.$this->getAliasNbPages(),0,0,'R');
    }
}

function reportText(string $value): string {
    $months=array_flip(['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC']);
    $value=preg_replace_callback('/\b(\d{1,2}) ([A-Z]{3}) (\d{1,4})\b/',static fn(array $m): string=>isset($months[$m[2]]) ? $m[1].'-'.($months[$m[2]]+1).'-'.$m[3] : $m[0],$value);
    return strtr($value,['BIRT:'=>'Geboorte:','DEAT:'=>'Overlijden:','CHR:'=>'Doop:','BAPM:'=>'Doop:',
        'BURI:'=>'Begrafenis:','CREM:'=>'Crematie:','MARR:'=>'Huwelijk:','DIV:'=>'Scheiding:',
        'MARL:'=>'Huwelijksvergunning:','BEF '=>'vóór ','AFT '=>'na ','ABT '=>'circa ']);
}

function consistencyPdf(array $report,string $path): void {
    $pdf=new ConsistencyPdf('L','mm','A4',true,'UTF-8',false);
    $pdf->reviewDate=substr($report['checked_at'],0,10);
    $pdf->SetCreator('Centipod');$pdf->SetAuthor('Centipod');$pdf->SetTitle('SCHUIT - Consistentiecontrole');
    $pdf->SetMargins(12,27,12);$pdf->SetAutoPageBreak(false,15);$pdf->setFontSubsetting(true);
    $pdf->SetFont('dejavusans','',9);$pdf->setCellPaddings(3,2,3,2);
    $widths=[51,70,64,88];
    $newPage=static function () use ($pdf,$widths): void {
        $pdf->AddPage();$pdf->SetY(27);$pdf->SetFont('dejavusans','B',9);$pdf->SetFillColor(15,76,117);$pdf->SetTextColor(255,255,255);
        foreach (['Onderwerp','Bevinding','Waarom inconsistent?','Mogelijke oplossing'] as $i=>$title) { $pdf->Cell($widths[$i],10,$title,0,0,'L',true); }
        $pdf->Ln(10);$pdf->SetFont('dejavusans','',9);$pdf->SetTextColor(38,50,56);
    };
    $newPage();
    if ($report['truncated']) {
        $pdf->SetFillColor(255,242,220);$pdf->MultiCell(273,10,'Veel signalen: dit PDF toont de eerste '.$report['reported'].' van '.$report['total'].'. Het overzicht op de uploadpagina telt alle signalen. Verbeter het bestand en upload opnieuw voor de resterende signalen.',0,'L',true,1);
    }
    if (!$report['total']) {
        $pdf->MultiCell(273,12,'Geen afwijkingen gevonden met deze regels. Dit is geen bewijs dat alle gegevens historisch juist zijn.',0,'L',false,1);
    }
    foreach ($report['rows'] as $index=>$row) {
        $texts=[$row['subject']."\n".implode(', ',$row['ids']),$row['finding'],$row['reason'],$row['solution']];
        $texts=array_map(__NAMESPACE__.'\\reportText',$texts);
        $height=12.0;
        foreach ($texts as $i=>$text) { $height=max($height,$pdf->getStringHeight($widths[$i],$text)+1); }
        if ($height>145) { throw new \RuntimeException('Een rapportregel is te groot. Neem contact op met de beheerder; er is niets geïmporteerd.'); }
        if ($pdf->GetY()+$height>194) { $newPage(); }
        $y=$pdf->GetY();$x=12.0;$pdf->SetFillColor(...($index%2 ? [242,246,248] : [255,255,255]));$pdf->SetDrawColor(220,229,234);
        foreach ($texts as $i=>$text) {
            $pdf->Rect($x,$y,$widths[$i],$height,'DF');
            // Plain text only: uploaded names cannot inject HTML, remote images or PDF links.
            $pdf->MultiCell($widths[$i],$height,$text,0,'L',false,0,$x,$y,true,0,false,true,$height,'T');$x+=$widths[$i];
        }
        $pdf->SetXY(12,$y+$height);
    }
    $temp=tempnam(dirname($path),'.report-');
    if ($temp===false) { throw new \RuntimeException('Rapport opslaan mislukt.'); }
    try { chmod($temp,0600);$pdf->Output($temp,'F');if (!rename($temp,$path)) { throw new \RuntimeException('Rapport opslaan mislukt.'); } }
    finally { if (is_file($temp)) { unlink($temp); } }
}
