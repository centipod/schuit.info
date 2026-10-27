<?php
declare(strict_types=1);
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\View;

// Independent of the selected theme: changing theme must not hide the challenge.
return new class () extends AbstractModule implements ModuleCustomInterface {
    use ModuleCustomTrait;
    public function title(): string { return 'SCHUIT aanmeldbeveiliging'; }
    public function description(): string { return 'Google reCAPTCHA bij aanmelden.'; }
    public function customModuleAuthorName(): string { return 'Centipod'; }
    public function customModuleVersion(): string { return '1.0.0'; }
    public function isEnabledByDefault(): bool { return true; }
    public function boot(): void {
        View::registerNamespace('schuit-login', __DIR__ . '/resources/views/');
        View::registerCustomView('::login-page', 'schuit-login::login-page');
    }
};
