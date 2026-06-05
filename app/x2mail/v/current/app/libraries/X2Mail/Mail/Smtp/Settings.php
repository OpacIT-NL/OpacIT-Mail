<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2022 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Smtp;

/**
 * @category MailSo
 * @package Net
 */
class Settings extends \X2Mail\Mail\Net\ConnectSettings
{
	public int
		$port = 25,
		$timeout = 60;

	public bool $viewErrors = false;

	public string $Ehlo;

	public function __construct()
	{
		parent::__construct();
		$oConfig = \X2Mail\Engine\Api::Config();
		$this->viewErrors = !!$oConfig->Get('labs', 'smtp_show_server_errors', false);
	}

	public static function fromArray(array $aSettings) : self
	{
		/** @var self $object */
		$object = parent::fromArray($aSettings);
		$object->useAuth = !empty($aSettings['useAuth']);
//		$object->viewErrors = !empty($aSettings['viewErrors']);
		return $object;
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		return \array_merge(
			parent::jsonSerialize(),
			[
//				'@Object' => 'Object/SmtpSettings',
				'useAuth' => $this->useAuth
//				'viewErrors' => $this->viewErrors
			]
		);
	}

}
