<?php

declare(strict_types=1);

namespace OCA\opacit_mail\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection
{
    public function __construct(
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string
    {
        return 'opacit_mail';
    }

    public function getName(): string
    {
        return 'OpacIT Mail';
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
