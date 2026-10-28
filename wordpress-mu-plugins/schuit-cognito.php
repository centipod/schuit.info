<?php
/** Plugin Name: SCHUIT aanmelden met AWS Cognito */
declare(strict_types=1);
require_once '/opt/schuit-security/cognito.php';
if (PHP_SAPI==='cli' || !\Schuit\Cognito\enabled()) {return;}
// No local-password or application-password bypass while Cognito is enabled.
add_filter('authenticate',static fn()=>new WP_Error('cognito_required','Meld u aan via de beveiligde aanmeldpagina.'),PHP_INT_MAX,3);
add_filter('wp_is_application_passwords_available','__return_false');
add_filter('auth_cookie_expiration',static fn()=>3600);
add_action('login_init',static function(): void {
    $action=$_GET['action']??'login';
    if ($action==='logout') {return;} // Native nonce check and local session destruction.
    try {
        if($action==='schuit_callback') {
            $identity=\Schuit\Cognito\finish('wordpress');$user=get_user_by('id',$identity['id']);
            if(!$user || strcasecmp($user->user_email,$identity['email'])!==0 || !$user->exists() || !$user->roles) {throw new RuntimeException('Local account unavailable');}
            wp_set_current_user($user->ID);wp_set_auth_cookie($user->ID,false,true);do_action('wp_login',$user->user_login,$user);
            \Schuit\Cognito\redirect(admin_url());
        }
        if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){\Schuit\Cognito\fail();}
        \Schuit\Cognito\begin('wordpress');
    } catch(\Schuit\Cognito\LoginCapacity $e) {\Schuit\Cognito\limited();} catch(Throwable $e) {error_log('SCHUIT Cognito WordPress login rejected');\Schuit\Cognito\fail();}
});
