<?php
declare(strict_types=1);

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\ModuleThemeTrait;

return new class () extends AbstractModule implements ModuleCustomInterface, ModuleThemeInterface {
    use ModuleCustomTrait;
    use ModuleThemeTrait;

    public function title(): string
    {
        return I18N::translate('Schuit modern');
    }

    public function description(): string
    {
        return I18N::translate('A crisp, modern theme for the Schuit family portal.');
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function customModuleAuthorName(): string
    {
        return 'GitHub Copilot';
    }

    public function customModuleVersion(): string
    {
        return '0.1.0';
    }

    public function stylesheets(): array
    {
        return [
            $this->assetUrl('css/theme.css'),
        ];
    }

    public function bootstrapColorScheme(): string
    {
        return 'light';
    }
};
