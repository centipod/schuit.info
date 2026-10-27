<?php
declare(strict_types=1);
require_once '/opt/schuit-security/runtime-guard.php';
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$file = tempnam('/tmp', 'schuit-guard-');
try {
    foreach ([['photo.svg','<svg xmlns="http://www.w3.org/2000/svg"/>'], ['photo.JPG','<svg xmlns="http://www.w3.org/2000/svg"/>'], ['tree.ged','<?xml version="1.0"?><root/>'], ['photo.jpg','<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg/>'], ['PHOTO.PHP.jpg','<?php echo 1;']] as [$name,$body]) {
        file_put_contents($file,$body);
        check(\Schuit\Security\unsafeUpload($name,$file), 'Unsafe upload accepted: '.$name);
    }
    file_put_contents($file,"0 HEAD\n1 CHAR UTF-8\n0 @I1@ INDI\n1 NAME Jan /Schuit/\n0 TRLR\n");
    check(!\Schuit\Security\unsafeUpload('familie.ged',$file),'GEDCOM blocked');
    $files=['files'=>['name'=>['familie.ged','bad.svg'],'tmp_name'=>[$file,$file],'error'=>[0,0]]];
    check(!\Schuit\Security\uploadsAreSafe($files),'Nested upload bypass');
    foreach (['jpeg','png','gif','bmp','webp'] as $format) {
        $image=imagecreatetruecolor(64,48);$fn='image'.$format;$fn($image,$file);imagedestroy($image);
        check(!\Schuit\Security\unsafeUpload('photo.'.$format,$file),'Raster blocked: '.$format);
        $decoded=imagecreatefromstring(file_get_contents($file));check($decoded!==false,'Image decoding failed: '.$format);
        $scaled=imagescale($decoded,32);check(imagesx($scaled)===32,'Resize failed: '.$format);imagedestroy($decoded);imagedestroy($scaled);
    }
    file_put_contents($file,'PRIVATE-FIXTURE');
    $doc=new DOMDocument();libxml_use_internal_errors(true);
    $doc->loadXML('<!DOCTYPE root [<!ENTITY test SYSTEM "file://'.$file.'">]><root>&test;</root>',LIBXML_NOENT);
    check(!str_contains($doc->textContent,'PRIVATE-FIXTURE'),'External entity resolved');
    foreach (['exec','passthru','shell_exec','system','proc_open','popen','pcntl_exec'] as $fn) {check(!function_exists($fn),'Process execution enabled: '.$fn);}
    check(!extension_loaded('imagick'),'Imagick remains');
    echo "Raster resize, GEDCOM, disguised/nested uploads, XXE and process restrictions passed.\n";
} finally {unlink($file);}
