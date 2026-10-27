<?php
declare(strict_types=1);

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\ModuleThemeTrait;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;

return new class () extends AbstractModule implements ModuleCustomInterface, ModuleThemeInterface {
    use ModuleCustomTrait;
    use ModuleThemeTrait {
        genealogyMenu as baseGenealogyMenu;
    }

    private const VIEW_NAMESPACE = 'schuit-modern-theme';

    public function boot(): void
    {
        // Override webtrees core's lists/families-table view to fix an
        // upstream bug (see resources/views/lists/families-table.phtml).
        View::registerNamespace(self::VIEW_NAMESPACE, $this->resourcesFolder() . 'views/');
        View::registerCustomView('::lists/families-table', self::VIEW_NAMESPACE . '::lists/families-table');
        View::registerCustomView('::layouts/default', self::VIEW_NAMESPACE . '::layouts/default');
        View::registerCustomView('::login-page', self::VIEW_NAMESPACE . '::login-page');
    }

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
        return 'Stichting Schu-y-i-ij-t';
    }

    public function customModuleVersion(): string
    {
        return '0.3.0';
    }

    public function stylesheets(): array
    {
        return [
            asset('css/webtrees.min.css'),
            $this->assetUrl('css/theme.css') . '&v=0.3.0',
        ];
    }

    public function bootstrapColorScheme(): string
    {
        return 'light';
    }

    /**
     * Add a persistent portal return link to webtrees primary navigation.
     *
     * @return array<Menu>
     */
    public function genealogyMenu(?Tree $tree): array
    {
        $menus = $this->baseGenealogyMenu($tree);

        array_unshift(
            $menus,
            new Menu(
                $this->portalReturnLabel(),
                '/',
                'menu-portal-return',
                ['rel' => 'external noopener']
            )
        );

        return $menus;
    }

    /**
     * "Return to website" isn't part of webtrees' translation catalog, so
     * I18N::translate() can't localize it automatically. Provide a small
     * manual lookup for the languages enabled on this site.
     */
    private function portalReturnLabel(): string
    {
        $labels = [
            'nl' => 'Terug naar de website',
        ];

        return $labels[I18N::languageTag()] ?? 'Return to website';
    }
};
