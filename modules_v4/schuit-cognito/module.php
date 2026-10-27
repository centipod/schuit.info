<?php
declare(strict_types=1);
use Fisharebest\Webtrees\{Auth,Session,Registry,Log};
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Module\{AbstractModule,ModuleCustomInterface,ModuleCustomTrait};
use Psr\Http\Message\{ResponseInterface,ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface,RequestHandlerInterface};
require_once '/opt/schuit-security/cognito.php';
return new class() extends AbstractModule implements ModuleCustomInterface,MiddlewareInterface {
    use ModuleCustomTrait;
    public function title(): string {return 'SCHUIT aanmelden met AWS';}
    public function description(): string {return 'Cognito-aanmelding met behoud van webtrees-rechten.';}
    public function customModuleAuthorName(): string {return 'Centipod';}
    public function customModuleVersion(): string {return '1.0.0';}
    public function isEnabledByDefault(): bool {return true;}
    private function denied(): ResponseInterface {
        error_log('SCHUIT Cognito webtrees login rejected');
        return response('<h1>Aanmelden niet voltooid</h1><p>Probeer opnieuw aan te melden. Neem contact op met het bestuur als dit blijft gebeuren.</p><a href="/tree/login">Opnieuw aanmelden</a>',403)->withHeader('Cache-Control','no-store')->withHeader('Referrer-Policy','no-referrer');
    }
    // Return through webtrees middleware: it must commit the DB transaction and
    // save/rotate the native session. Exiting from boot() would roll it back.
    public function getCallbackAction(ServerRequestInterface $request): ResponseInterface {
        try {
            if(!\Schuit\Cognito\enabled()){return $this->denied();}
            $identity=\Schuit\Cognito\finish('webtrees');$user=Registry::container()->get(UserService::class)->find($identity['id']);
            if(!$user || strcasecmp($user->email(),$identity['email'])!==0 || $user->getPreference(UserInterface::PREF_IS_EMAIL_VERIFIED)!=='1' || $user->getPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED)!=='1'){throw new RuntimeException('Local account unavailable');}
            Auth::login($user);Session::put('schuit_cognito_until',time()+3600);
            Session::put('language',$user->getPreference(UserInterface::PREF_LANGUAGE,'nl'));Session::put('theme',$user->getPreference(UserInterface::PREF_THEME));
            $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE,(string)time());Log::addAuthenticationLog('Cognito login: '.$user->userName());
            return redirect(\Schuit\Cognito\config()['origin'].'/tree/')->withHeader('Cache-Control','no-store')->withHeader('Referrer-Policy','no-referrer');
        } catch(Throwable $e){return $this->denied();}
    }
    public function process(ServerRequestInterface $request,RequestHandlerInterface $handler): ResponseInterface {
        if(!\Schuit\Cognito\enabled()){return $handler->handle($request);}
        try {
            if(Auth::check() && (int)Session::get('schuit_cognito_until',0)<time()) {
                Auth::logout();return redirect(\Schuit\Cognito\config()['origin'].'/tree/login');
            }
            $path=\Schuit\Cognito\treeRoute($_SERVER,$_GET);
            if(preg_match('~^/(?:login|register|password-request|password-reset)(?:/|$)~D',$path)) {
                if($request->getMethod()!=='GET'){return $this->denied();}
                return redirect(\Schuit\Cognito\authorizationUrl('webtrees'))->withHeader('Cache-Control','no-store')->withHeader('Referrer-Policy','no-referrer');
            }
            return $handler->handle($request);
        }catch(Throwable $e){return $this->denied();}
    }
};
