<?php
if(PHP_SAPI!=='cli'||getenv('CI_FIXTURE')!=='synthetic-only'){throw new RuntimeException('Fixture only');}
require '/var/www/html/wp-load.php';
echo json_encode(['manager'=>get_user_by('login','ci_manager')->ID,'reader'=>get_user_by('login','ci_reader')->ID]);
