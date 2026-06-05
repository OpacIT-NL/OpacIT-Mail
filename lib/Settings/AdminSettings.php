<?php

namespace OCA\opacit_mail\Settings;

use OCA\opacit_mail\Util\EngineHelper;
use OCA\opacit_mail\Util\NavigationTitle;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IAppConfig;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
    public function __construct(
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getForm()
    {
        $this->engineHelper->loadApp();

        $parameters = [];
        $parameters['opacit_mail-debug-log'] = $this->appConfig->getValueString('opacit_mail', 'debug_log', '0') === '1';
        $oConfig = \opacit_mail\Engine\Api::Config();

        // opacit_mail is OIDC-first, no legacy import

        $parameters['opacit_mail-debug'] = $oConfig->Get('debug', 'enable', false);

        // Check for nextcloud plugin update
        foreach (\opacit_mail\Engine\Repository::getPackagesList()['List'] as $plugin) {
            if ('nextcloud' == $plugin['id'] && $plugin['canBeUpdated']) {
                \opacit_mail\Engine\Repository::installPackage('plugin', 'nextcloud');
            }
        }

        $app_path = $oConfig->Get('webmail', 'app_path');
        if (!$app_path) {
            $webPath = $this->appManager->getAppWebPath('opacit_mail');
            $app_path = \preg_replace(
                '#(?<!:)/+#',
                '/',
                \rtrim($webPath, '/') . '/app/'
            );
            $oConfig->Set('webmail', 'app_path', $app_path);
            $oConfig->Set('webmail', 'theme', 'opacit_mail');
            $oConfig->Save();
        }
        $parameters['opacit_mail-app-path'] = $oConfig->Get('webmail', 'app_path', false);
        $parameters['opacit_mail-nc-lang'] = !$oConfig->Get('webmail', 'allow_languages_on_settings', true);
        $parameters['opacit_mail-version'] = $this->appManager->getAppVersion('opacit_mail');

        $parameters['menu_title'] = NavigationTitle::storedOverride($this->appConfig);
        $parameters['menu_title_default'] = NavigationTitle::DEFAULT;
        $parameters['attachment_size_limit'] = (int) $oConfig->Get('webmail', 'attachment_size_limit', 25);
        $parameters['show_attachment_thumbnail'] = (bool) $oConfig->Get('interface', 'show_attachment_thumbnail', true);
        $parameters['openpgp'] = (bool) $oConfig->Get('security', 'openpgp', true);
        $parameters['gnupg'] = (bool) $oConfig->Get('security', 'gnupg', true);
        $parameters['opacit_mail_version'] = $parameters['opacit_mail-version'];

        \OCP\Util::addScript('opacit_mail', 'setup-wizard');
        \OCP\Util::addStyle('opacit_mail', 'setup-wizard');
        return new TemplateResponse('opacit_mail', 'admin-local', $parameters);
    }

    public function getSection()
    {
        return 'opacit_mail';
    }

    public function getPriority()
    {
        return 50;
    }
}
