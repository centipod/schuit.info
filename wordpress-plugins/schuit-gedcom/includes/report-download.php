<?php
declare(strict_types=1);
namespace Schuit\Gedcom;

/** Called only after capability, job ownership and nonce checks. Returns a verified file handle. */
function openConsistencyReport(array $job) {
    $meta=$job['analysis']['consistency'] ?? [];
    if (!is_string($meta['pdf_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D',$meta['pdf_sha256'])) { throw new \RuntimeException('Rapport niet beschikbaar.'); }
    $folder=directory($job['id']);$base=realpath(root());$real=realpath($folder);$path=$folder.'/consistency.pdf';
    if ($base===false||$real===false||$real!==$base.'/'.$job['id']||is_link($folder)||is_link($path)) { throw new \RuntimeException('Rapport niet beschikbaar.'); }
    if (!is_file($path)) { throw new \RuntimeException('Rapport niet beschikbaar.'); }
    $handle=fopen($path,'rb');
    if (!$handle) { throw new \RuntimeException('Rapport niet beschikbaar.'); }
    try {
        $stat=fstat($handle);
        if ($stat===false||($stat['mode'] & 0170000)!==0100000||$stat['size']<8||$stat['size']>32*1024*1024) { throw new \RuntimeException('Rapport niet beschikbaar.'); }
        $hash=hash_init('sha256');hash_update_stream($hash,$handle);
        if (!hash_equals($meta['pdf_sha256'],hash_final($hash))) { throw new \RuntimeException('Rapport niet beschikbaar.'); }
        rewind($handle);return $handle;
    } catch (\Throwable $e) { fclose($handle);throw $e; }
}
