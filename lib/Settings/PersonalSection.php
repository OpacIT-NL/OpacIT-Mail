<?php

declare(strict_types=1);

namespace OCA\opacit_mail\Settings;

use OCA\opacit_mail\Util\EngineHelper;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection
{
    public function __construct(
        private IURLGenerator $urlGenerator,
        private IL10N $l,
        private EngineHelper $engineHelper,
    ) {
    }

    public function getID(): string
    {
        return 'opacit_mail';
    }

    public function getName(): string
    {
        try {
            $this->engineHelper->loadApp();
            return \opacit_mail\Engine\Api::Config()->Get('webmail', 'title', 'OpacIT Mail') . ' ' . $this->l->t('Settings');
        } catch (\Throwable) {
            return 'OpacIT Mail ' . $this->l->t('Settings');
        }
    }

    public function getPriority(): int
    {
        return 75;
    }

    public function getIcon(): string
    {
        return $this->urlGenerator->imagePath('opacit_mail', 'logo-64x64.png');
    }
}
