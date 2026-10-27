<?php
declare(strict_types=1);
// In-memory records only, using the real tree's privacy preferences.
define('SCHUIT_GEDCOM_TEST_LIBRARY', true);
require dirname(__DIR__) . '/scripts/gedcom-worker.php';
use Fisharebest\Webtrees\{Auth, GuestUser, Registry};
Auth::login(new GuestUser());
$tree = target();
$factory = Registry::individualFactory();
$living = $factory->new('SECURITYLIVING', "0 @SECURITYLIVING@ INDI\n1 NAME Synthetic /Living/\n1 BIRT\n2 DATE 1 JAN 2026", null, $tree);
$deceased = $factory->new('SECURITYDEAD', "0 @SECURITYDEAD@ INDI\n1 NAME Synthetic /Deceased/\n1 BIRT\n2 DATE 1 JAN 1800\n1 DEAT\n2 DATE 1 JAN 1900", null, $tree);
if ($living->canShow(Auth::PRIV_PRIVATE) || $living->canShowName(Auth::PRIV_PRIVATE)) { throw new RuntimeException('Living-person privacy failed'); }
if (!$deceased->canShow(Auth::PRIV_PRIVATE)) { throw new RuntimeException('Public historical access changed'); }
echo "Anonymous visitors cannot see living-person records or names; historical public access retained. In-memory fixtures only.\n";
