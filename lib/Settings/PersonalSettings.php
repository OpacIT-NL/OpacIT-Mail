<?php

declare(strict_types=1);

namespace OCA\opacit_mail\Settings;

use OCA\opacit_mail\Util\EngineHelper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getForm()
    {
        $this->engineHelper->loadApp();
        $brandName = \opacit_mail\Engine\Api::Config()->Get('webmail', 'title', 'OpacIT Mail');

        return new TemplateResponse('opacit_mail', 'personal_settings', [
            'brandName' => $brandName,
            'settingsUrl' => $this->urlGenerator->linkToRoute('opacit_mail.page.index') . '#/settings/accounts',
        ], '');
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
