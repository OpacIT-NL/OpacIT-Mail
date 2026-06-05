<?php

declare(strict_types=1);

namespace OCA\X2Mail\Util;

use OCP\IAppConfig;

final class NavigationTitle
{
    public const APP_CONFIG_KEY = 'menu-title';

    public const DEFAULT = 'X2Mail';

    public static function resolve(IAppConfig $appConfig): string
    {
        $custom = \trim($appConfig->getValueString('x2mail', self::APP_CONFIG_KEY, ''));

        return $custom !== '' ? $custom : self::DEFAULT;
    }

    public static function storedOverride(IAppConfig $appConfig): string
    {
        return \trim($appConfig->getValueString('x2mail', self::APP_CONFIG_KEY, ''));
    }

    public static function validate(string $title): ?string
    {
        $title = \trim($title);
        if (\strlen($title) > 64 || \preg_match('/[\x00-\x1f]/', $title)) {
            return 'Invalid menu title';
        }

        return null;
    }
}
