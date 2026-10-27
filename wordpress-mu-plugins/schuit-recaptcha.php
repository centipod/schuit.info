<?php
/** Plugin Name: SCHUIT aanmeldbeveiliging */
declare(strict_types=1);
require_once '/opt/schuit-security/recaptcha.php';
add_action('login_form', static function (): void { echo \Schuit\Captcha\widget(); });
