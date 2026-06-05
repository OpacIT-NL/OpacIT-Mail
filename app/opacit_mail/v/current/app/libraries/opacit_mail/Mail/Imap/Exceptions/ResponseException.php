<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace opacit_mail\Mail\Imap\Exceptions;

/**
 * @category MailSo
 * @package Imap
 * @subpackage Exceptions
 */
class ResponseException extends \opacit_mail\Mail\RuntimeException
{
	private $oResponses;

	public function __construct(?\opacit_mail\Mail\Imap\ResponseCollection $oResponses = null, string $sMessage = '', int $iCode = 0, ?\Throwable $oPrevious = null)
	{
		$this->oResponses = $oResponses;
		if (!$sMessage && $response = $this->GetLastResponse()) {
			$sMessage = ($response->OptionalResponse[0] ?? '') . ' ' . $response->HumanReadable;
		}
		parent::__construct($sMessage, $iCode, $oPrevious);
	}

	public function GetResponseStatus() : ?string
	{
		$oItem = $this->GetLastResponse();
		return $oItem && $oItem->IsStatusResponse ? $oItem->StatusOrIndex : null;
	}

	public function GetResponses() : ?\opacit_mail\Mail\Imap\ResponseCollection
	{
		return $this->oResponses;
	}

	public function GetLastResponse() : ?\opacit_mail\Mail\Imap\Response
	{
		return $this->oResponses ? $this->oResponses->getLast() : null;
	}
}
