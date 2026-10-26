<?php
declare(strict_types=1);
require is_file(__DIR__.'/../modules_v4/schuit-import/normalize.php')
    ? __DIR__.'/../modules_v4/schuit-import/normalize.php'
    : '/var/www/html/tree/modules_v4/schuit-import/normalize.php';
[$script,$source,$destination]=$argv;
if(realpath($source)===realpath($destination) || file_exists($destination)){throw new RuntimeException('Refusing to overwrite original or existing output');}
$input=fopen($source,'rb');$output=fopen($destination,'xb');chmod($destination,0600);
$counts=['individuals'=>0,'families'=>0,'name_lines_changed'=>0];$header=false;$trailer=false;
while(($line=fgets($input))!==false){
 if(preg_match('/^0 @[^@]+@ INDI(?:[\r\n]|$)/',$line)){++$counts['individuals'];}
 if(preg_match('/^0 @[^@]+@ FAM(?:[\r\n]|$)/',$line)){++$counts['families'];}
 $header=$header||trim($line)==='0 HEAD';$trailer=$trailer||trim($line)==='0 TRLR';
 $new=schuit_normalize_gedcom_line($line);if($new!==$line){++$counts['name_lines_changed'];}
 if(fwrite($output,$new)===false){throw new RuntimeException('Write failed');}
}
fclose($input);fclose($output);
if(!$header||!$trailer){unlink($destination);throw new RuntimeException('Incomplete GEDCOM file');}
echo json_encode($counts,JSON_PRETTY_PRINT)."\n";
