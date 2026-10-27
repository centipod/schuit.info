<?php
declare(strict_types=1);
// Test-only CLI helper. Create/remove only a random reserved-prefix account.
if (PHP_SAPI !== 'cli') { exit(1); }
define('SCHUIT_GEDCOM_TEST_LIBRARY', true);
require dirname(__DIR__) . '/scripts/gedcom-worker.php';
use Fisharebest\Webtrees\{DB, Registry};
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Services\UserService;
$service = Registry::container()->get(UserService::class);
if ($argv[1] === 'create') {
    $name = 'securitytest_' . bin2hex(random_bytes(8));
    $password = bin2hex(random_bytes(32));
    $before = DB::table('user')->count();
    $user = $service->create($name, 'Temporary security verification', $name.'@example.invalid', $password);
    $user->setPreference(UserInterface::PREF_IS_EMAIL_VERIFIED, '1');
    $user->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '1');
    $user->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '1');
    $user->setPreference(UserInterface::PREF_LANGUAGE, 'en-US');
    echo json_encode(['name'=>$name,'password'=>$password,'before'=>$before]);
} elseif ($argv[1] === 'delete') {
    $name = $argv[2];
    if (!preg_match('/^securitytest_[a-f0-9]{16}$/D', $name)) { exit(1); }
    $user = $service->findByIdentifier($name);
    if ($user !== null) { $service->delete($user); }
    echo json_encode(['remaining'=>DB::table('user')->count()]);
} else { exit(1); }
