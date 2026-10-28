<?php
declare(strict_types=1);
require __DIR__.'/../wordpress-plugins/schuit-gedcom/includes/privacy.php';
use function Schuit\Gedcom\{exclusions,assertNotExcluded};
$path=tempnam(sys_get_temp_dir(),'privacy-');
try {
    file_put_contents($path,json_encode(['version'=>1,'excluded_xrefs'=>['DELETED_PERSON']]));
    $list=exclusions($path);
    assertNotExcluded('PUBLIC_HISTORY',$list);
    try { assertNotExcluded('DELETED_PERSON',$list); throw new LogicException('Re-import permitted'); } catch(RuntimeException $e) { echo "PASS excluded record cannot be re-imported\n"; }
    file_put_contents($path,'not json');
    try { exclusions($path); throw new LogicException('Invalid register accepted'); } catch(JsonException $e) { echo "PASS malformed privacy register fails closed\n"; }
} finally {unlink($path);}
