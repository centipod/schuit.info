<?php
declare(strict_types=1);
// Disposable queue data only; no WordPress, database, network or real GEDCOM.
use Schuit\Gedcom\AnalysisStopped;
use function Schuit\Gedcom\{directory, enqueueUpload, jobs, read, reviewJob, root, saveAnalysis, write};
require dirname(__DIR__) . '/wordpress-plugins/schuit-gedcom/includes/jobs.php';

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function rejected(callable $action, string $class = RuntimeException::class): void {
    try { $action(); } catch (Throwable $e) {
        check($e instanceof $class, 'Unexpected rejection: ' . get_class($e)); return;
    }
    throw new RuntimeException('Expected rejection');
}
function upload(int $owner = 1): array {
    return enqueueUpload($owner, 'synthetic.ged', 10, static function (string $path): void {
        file_put_contents($path, 'synthetic!');
    });
}
function fixture(string $state, int $owner = 1): array {
    $id = bin2hex(random_bytes(16)); mkdir(directory($id), 0700);
    file_put_contents(directory($id) . '/original.ged', 'synthetic!');
    $job = ['id'=>$id,'owner'=>$owner,'filename'=>'old.ged','bytes'=>10,'state'=>$state,'created_at'=>'2026-01-01T00:00:00+00:00'];
    write($job); return read($id);
}
function clean(string $path): void {
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isDir()) { clean($entry->getPathname()); } else { unlink($entry->getPathname()); }
    }
    rmdir($path);
}
function scenario(callable $action): void {
    $path = sys_get_temp_dir() . '/schuit-supersession-' . bin2hex(random_bytes(8)); mkdir($path, 0700);
    putenv('SCHUIT_GEDCOM_JOBS=' . $path);
    try { $action(); } finally { clean($path); }
}

if (($argv[1] ?? '') === 'child') {
    putenv('SCHUIT_GEDCOM_JOBS=' . $argv[2]);
    $deadline = microtime(true) + 10;
    while (!is_file(root() . '/start')) {
        if (microtime(true) > $deadline) { exit(2); }
        usleep(1000);
    }
    try {
        $result = $argv[3] === 'confirm' ? reviewJob($argv[4], true, 1) : upload();
        echo json_encode(['state'=>$result['state'], 'id'=>$result['id']]);
    } catch (RuntimeException) { echo json_encode(['rejected'=>true]); }
    exit;
}

function race(array $actions): array {
    $children = [];
    foreach ($actions as [$action, $id]) {
        $process = proc_open([PHP_BINARY, __FILE__, 'child', root(), $action, $id],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        check(is_resource($process), 'Child start'); fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    touch(root() . '/start'); $results = [];
    foreach ($children as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $errors === '', 'Child failed: ' . $errors);
        $results[] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
    }
    return $results;
}

$tests = [
    'full unreviewed queue and other owners are superseded, originals retained' => static function (): void {
        $old = [fixture('uploaded'), fixture('analyzing', 2), fixture('ready', 3)];
        $new = upload(4);
        check(read($new['id'])['state'] === 'uploaded', 'New upload requires analysis');
        foreach ($old as $job) {
            $current = read($job['id']);
            check($current['state'] === 'cancelled' && $current['superseded_by'] === $new['id'], 'Older job retired');
            check(file_get_contents(directory($job['id']) . '/original.ged') === 'synthetic!', 'Original retained');
        }
        check(count(array_filter(jobs(), static fn(array $j): bool => $j['state'] === 'uploaded')) === 1, 'Only latest waits');
    },
    'failed storage and rejected size leave the previous upload usable' => static function (): void {
        $old = fixture('ready');
        rejected(static fn() => enqueueUpload(1, 'bad.ged', 10, static function (string $path): void {
            file_put_contents($path, 'partial'); throw new RuntimeException('Synthetic disk failure');
        }));
        rejected(static fn() => enqueueUpload(1, 'empty.ged', 0, static function (): void {}));
        check(read($old['id']) === $old && count(jobs()) === 1, 'Rejected upload cannot supersede');
        check(count(glob(root() . '/*', GLOB_ONLYDIR)) === 1, 'Incomplete directory removed');
    },
    'storage limit still counts superseded originals' => static function (): void {
        $old = fixture('ready'); $old['bytes'] = 500 * 1024 * 1024; write($old); $before = read($old['id']);
        rejected(static fn() => upload());
        check(read($old['id']) === $before && count(jobs()) === 1, 'Quota cannot discard history');
    },
    'stale analysis progress, success and failure cannot revive an old job' => static function (): void {
        $old = fixture('analyzing'); upload(); $retired = read($old['id']);
        foreach (['analyzing','ready','failed'] as $state) {
            $snapshot = $old; $snapshot['state'] = $state;
            rejected(static fn() => saveAnalysis($snapshot), AnalysisStopped::class);
            check(read($old['id']) === $retired, 'Stale analysis changed history');
        }
    },
    'stale review cannot confirm or cancel a superseded job' => static function (): void {
        $old = fixture('ready'); upload();
        rejected(static fn() => reviewJob($old['id'], true, 1));
        rejected(static fn() => reviewJob($old['id'], false, 1));
        check(read($old['id'])['state'] === 'cancelled', 'Stale form queued an import');
    },
    'analysis failure of the replacement never approves or revives an older upload' => static function (): void {
        $old = fixture('ready'); $new = upload(); $new['state'] = 'analyzing'; saveAnalysis($new);
        $new['state'] = 'failed'; saveAnalysis($new);
        rejected(static fn() => reviewJob($old['id'], true, 1));
        check(read($old['id'])['state'] === 'cancelled' && read($new['id'])['state'] === 'failed', 'Unexpected reactivation');
    },
    'confirmed work still respects the outstanding queue limit' => static function (): void {
        $old = [fixture('queued'), fixture('backing_up'), fixture('importing')];
        rejected(static fn() => upload());
        foreach ($old as $job) { check(read($job['id']) === $job, 'Confirmed work changed'); }
    },
    'accepted upload survives an interrupted supersession request' => static function (): void {
        $old = fixture('ready'); $new = fixture('uploaded'); $new['supersedes'] = [$old['id']]; write($new);
        rejected(static fn() => reviewJob($old['id'], true, 1));
        check(read($old['id'])['superseded_by'] === $new['id'], 'Interrupted decision not recovered');
        $before = read($old['id']);
        $new['state'] = 'analyzing'; saveAnalysis($new);
        check(read($old['id']) === $before, 'Recovery must not refresh history retention');
    },
    'concurrent uploads leave one review candidate even within the same second' => static function (): void {
        fixture('ready'); $results = race([['upload',''], ['upload',''], ['upload','']]);
        check(count(array_filter($results, static fn(array $j): bool => !isset($j['rejected']))) === 3, 'Upload rejected');
        check(count(array_filter(jobs(), static fn(array $j): bool => $j['state'] === 'uploaded')) === 1, 'Multiple active uploads');
        check(count(array_filter(jobs(), static fn(array $j): bool => isset($j['superseded_by']))) === 3, 'History missing');
    },
    'concurrent confirmation and replacement have one consistent outcome' => static function (): void {
        $old = fixture('ready'); $results = race([['confirm',$old['id']], ['upload','']]);
        $current = read($old['id']);
        if (isset($results[0]['rejected'])) {
            check($current['state'] === 'cancelled' && isset($current['superseded_by']), 'Replacement wins');
        } else {
            check($current['state'] === 'queued' && !isset($current['superseded_by']), 'Confirmation wins');
        }
    },
];
foreach (['queued','backing_up','importing','completed','failed','cancelled'] as $state) {
    $tests['preserve ' . $state] = static function () use ($state): void {
        $old = fixture($state); upload();
        check(read($old['id']) === $old, 'Protected/history job changed: ' . $state);
    };
}
foreach ($tests as $name=>$test) { scenario($test); echo "PASS: $name\n"; }
echo count($tests) . " supersession scenarios passed.\n";
