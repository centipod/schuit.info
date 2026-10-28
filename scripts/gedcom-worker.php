<?php
declare(strict_types=1);

use Fisharebest\Webtrees\{DB, I18N, Registry, Tree, Webtrees};
use Fisharebest\Webtrees\Services\GedcomImportService;
use function Schuit\Gedcom\{analyze, directory, lines, read, root, write};

if (PHP_SAPI !== 'cli') { exit(1); }
umask(0077); set_time_limit(0);
$plugin = getenv('SCHUIT_GEDCOM_PLUGIN') ?: '/var/www/html/wp-content/plugins/schuit-gedcom';
require_once $plugin . '/includes/jobs.php';
require_once $plugin . '/includes/analyze.php';
// Deployment mounts the complete plugin separately; scripts can also run in a checkout.
chdir('/var/www/html/tree');
require '/var/www/html/tree/vendor/autoload.php';
Webtrees::new()->bootstrap();
I18N::init('en-US', true);
$c = parse_ini_file(getenv('WEBTREES_CONFIG_PATH') ?: 'data/config.ini.php', false, INI_SCANNER_RAW);
DB::connect($c['dbtype'] ?? 'mysql', $c['dbhost'], (string)($c['dbport'] ?? '3306'), $c['dbname'], $c['dbuser'], $c['dbpass'], $c['tblpfx'], '', '', '', false);

function target(): Tree {
    $row = DB::table('gedcom')->where('gedcom_id', (int)(getenv('SCHUIT_GEDCOM_TREE_ID') ?: 7))->first();
    if (!$row) { throw new RuntimeException('Het ingestelde doelbestand bestaat niet.'); }
    return Tree::fromDB($row);
}

function baseline(Tree $tree): array {
    $id = $tree->id();
    if ((int)DB::table('gedcom')->where('gedcom_id', $id)->value('imported') !== 1 ||
        DB::table('gedcom_chunk')->where('gedcom_id', $id)->where('imported', '<>', 1)->exists() ||
        DB::table('change')->where('gedcom_id', $id)->where('status', 'pending')->exists()) {
        throw new RuntimeException('Webtrees heeft een lopende import of onbeoordeelde wijzigingen. Rond deze eerst af.');
    }
    $result = ['id'=>$id, 'title'=>$tree->title(), 'counts'=>[], 'fingerprint'=>[]];
    foreach (['INDI'=>['individuals','i_file','i_gedcom'], 'FAM'=>['families','f_file','f_gedcom'],
        'SOUR'=>['sources','s_file','s_gedcom'], 'OBJE'=>['media','m_file','m_gedcom'], 'OTHER'=>['other','o_file','o_gedcom']] as $tag=>[$table,$key,$data]) {
        // Change detector for accidental concurrent edits, not an integrity/signature primitive.
        $row = DB::table($table)->where($key,$id)->selectRaw("COUNT(*) AS n, COALESCE(SUM(CRC32($data)),0) AS checksum")->first();
        $result['counts'][$tag] = (int)$row->n;
        $result['fingerprint'][$tag] = (string)$row->checksum;
    }
    return $result;
}

function imported(string $id, Tree $tree): bool {
    return DB::table('gedcom_setting')->where('gedcom_id', $tree->id())->where('setting_name','SCHUIT_IMPORT_JOB')->value('setting_value') === $id;
}

function requireTransactionalTables(): void {
    $engines = DB::connection()->select('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?', ['BASE TABLE']);
    foreach ($engines as $engine) {
        if (strtoupper($engine->ENGINE) !== 'INNODB') { throw new RuntimeException('De database ondersteunt nog geen veilige transactionele import. Neem contact op met de beheerder.'); }
    }
}

function importRecords(string $folder, Tree $tree, array &$job): int {
    $service = Registry::container()->get(GedcomImportService::class);
    $handle = fopen($folder . '/normalized.ged','rb'); $record = ''; $done = 0; $started = time();
    $import = static function (string $record) use ($service, $tree, &$done, &$job, $started): void {
        $service->importRecord(rtrim($record, "\n"), $tree, false); ++$done;
        if ($done % 100 === 0) {
            if (time() - $started > 1800) { throw new RuntimeException('De verwerking duurde te lang. De vorige gegevens blijven behouden. Neem contact op met de beheerder.'); }
            $job['progress'] = $done . ' / ' . $job['analysis']['records'] . ' records verwerkt'; write($job);
        }
    };
    try {
        foreach (lines($handle) as $line) {
            if (str_starts_with($line, '0 ') && $record !== '') { $import($record); $record = ''; }
            $record .= $line . "\n";
        }
        if ($record !== '') { $import($record); }
    } finally { fclose($handle); }
    return $done;
}

function processImport(array &$job, Tree $tree): void {
    $folder = directory($job['id']);
    if (!hash_equals($job['normalized_sha256'], hash_file('sha256', $folder . '/normalized.ged')) ||
        !hash_equals($job['analysis']['sha256'], hash_file('sha256', $folder . '/original.ged'))) {
        throw new RuntimeException('Het bestand is gewijzigd sinds de controle. Upload het opnieuw.');
    }
    \Schuit\Gedcom\verifyPrivacyFile($folder . '/normalized.ged');
    $maintenance = fopen(root() . '/webtrees.lock', 'c');
    if (!flock($maintenance, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Webtrees is nog bezig. Upload opnieuw en probeer het later.'); }
    if (baseline($tree) !== $job['baseline']) { throw new RuntimeException('De gegevens in Webtrees zijn gewijzigd sinds de controle. Upload het bestand opnieuw om de nieuwe verschillen te beoordelen.'); }
    requireTransactionalTables();
    // Match the native webtrees CLI import, but keep the entire operation atomic,
    // include the last record, and record completion in the SAME transaction.
    DB::connection()->beginTransaction();
    try {
        DB::table('gedcom')->where('gedcom_id',$tree->id())->lockForUpdate()->first();
        $job['state'] = 'importing'; $job['progress'] = 'Import voorbereiden…'; write($job);
        DB::table('gedcom')->where('gedcom_id',$tree->id())->update(['imported'=>0]);
        $previousKeepMedia = $tree->getPreference('keep_media','0');
        $tree->setPreference('keep_media','0');
        foreach (['individuals'=>'i_file','families'=>'f_file','sources'=>'s_file','other'=>'o_file',
            'places'=>'p_file','placelinks'=>'pl_file','name'=>'n_file','dates'=>'d_file','change'=>'gedcom_id',
            'link'=>'l_file','media_file'=>'m_file','media'=>'m_file','gedcom_chunk'=>'gedcom_id'] as $table=>$column) {
            DB::table($table)->where($column,$tree->id())->delete();
        }
        $done = importRecords($folder, $tree, $job);
        foreach (['INDI'=>['individuals','i_file'],'FAM'=>['families','f_file']] as $tag=>[$table,$key]) {
            if (DB::table($table)->where($key,$tree->id())->count() !== $job['analysis']['counts'][$tag]) {
                throw new RuntimeException('De geïmporteerde aantallen komen niet overeen. De vorige gegevens blijven behouden.');
            }
        }
        DB::table('gedcom')->where('gedcom_id',$tree->id())->update(['imported'=>1,'gedcom_filename'=>$job['filename']]);
        $tree->setPreference('SCHUIT_IMPORT_JOB', $job['id']);
        $tree->setPreference('keep_media', $previousKeepMedia);
        DB::connection()->commit();
        $job['state'] = 'completed'; $job['progress'] = $done . ' records verwerkt en aantallen geverifieerd.';
        $job['completed_at'] = gmdate('c'); write($job);
    } catch (Throwable $e) {
        if (DB::connection()->transactionLevel() > 0) { DB::connection()->rollBack(); }
        throw $e;
    } finally { flock($maintenance, LOCK_UN); fclose($maintenance); }
}

if (defined('SCHUIT_GEDCOM_TEST_LIBRARY')) { return; }
$id = $argv[2] ?? ''; $action = $argv[1] ?? '';
$job = read($id);
$worker = fopen(root() . '/worker.lock', 'c');
if (!flock($worker, LOCK_EX | LOCK_NB)) { exit(75); }
try {
    $tree = target();
    if ($action === 'analyze' && in_array($job['state'], ['uploaded','analyzing'], true)) {
        $job['state'] = 'analyzing'; write($job);
        $job['baseline'] = baseline($tree);
        $job['analysis'] = analyze(directory($id) . '/original.ged', directory($id) . '/normalized.ged', static function (int $lines, int $records) use (&$job): void {
            $job['progress'] = $lines . ' regels / ' . $records . ' records gecontroleerd'; write($job);
        });
        foreach (['INDI'=>'personen','FAM'=>'gezinnen'] as $tag=>$label) {
            if ($job['analysis']['counts'][$tag] < $job['baseline']['counts'][$tag]) {
                $job['analysis']['warnings'][] = 'Dit bestand bevat minder ' . $label . ' dan Webtrees. Controleer of het bestand volledig is.';
            }
        }
        $job['normalized_sha256'] = hash_file('sha256', directory($id) . '/normalized.ged');
        $job['state'] = 'ready'; $job['progress'] = 'Bestandscontrole afgerond. Er is nog niets geïmporteerd.'; write($job);
    } elseif ($action === 'import' && $job['state'] === 'backing_up' && !empty($job['backup_key'])) {
        if (imported($id,$tree)) { $job['state']='completed'; write($job); }
        else { processImport($job,$tree); }
    } elseif ($action === 'recover' && $job['state'] === 'importing') {
        if (imported($id,$tree)) { $job['state']='completed'; $job['progress']='Import voltooid; status hersteld na onderbreking.'; write($job); }
        else { throw new RuntimeException('De verwerking is onderbroken en teruggedraaid. Upload het bestand opnieuw.'); }
    } else { throw new RuntimeException('De taak is niet klaar voor deze actie.'); }
    echo json_encode(['id'=>$id,'state'=>$job['state']]) . "\n";
} catch (Throwable $e) {
    // A DB exception can embed private GEDCOM content. Never expose it to users/journald.
    if (isset($tree) && imported($id,$tree)) { $job['state']='completed'; $job['progress']='Import voltooid.'; }
    else {
        $job['state']='failed';
        $job['error'] = get_class($e) === RuntimeException::class ? $e->getMessage() : 'De verwerking is niet voltooid. De bestaande gegevens blijven behouden. Neem contact op met de beheerder met het taaknummer.';
        $job['diagnostic_class']=get_class($e);
    }
    write($job); echo json_encode(['id'=>$id,'state'=>$job['state'],'error_class'=>get_class($e)]) . "\n"; exit(1);
}
