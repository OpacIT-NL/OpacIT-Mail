<?php

namespace X2Mail\Engine\SMime;

use X2Mail\Engine\File\Temporary;

/**
 * PHP 8.3.0 PKCS7_NOOLDMIMETYPE
 */

class OpenSSL
{
	private string $homedir;
	private array $headers = [];
	private int $flags = 0;
	private int $cipher_algo = \OPENSSL_CIPHER_AES_128_CBC; // \OPENSSL_CIPHER_AES_256_CBC
	private ?string $untrusted_certificates_filename = null;

	// Used for sign and decrypt
	private $certificate; // OpenSSLCertificate|array|string
	private $privateKey; // OpenSSLAsymmetricKey|OpenSSLCertificate|array|string

	function __construct(string $homedir)
	{
		$this->homedir = $homedir;
	}

	public function getCertificate(string $filename)/* : string*/
	{
		return \file_get_contents("{$this->homedir}/{$filename}");
	}

	public function storeCertificate(string $certificate) : bool
	{
		$data = \openssl_x509_parse(\openssl_x509_read($certificate));
		if (!$data) {
			\X2Mail\Engine\Log::error('OpenSSL', "parse: " . \openssl_error_string());
			return false;
		}
		$key = \str_replace(':', '', $data['extensions']['subjectKeyIdentifier'] ?? $data['hash']);
		$key = \basename($key);
		$filename = "{$this->homedir}/{$key}.crt";
		if (\file_exists($filename)) {
			\X2Mail\Engine\Log::debug('OpenSSL', "certificate {$key} already imported");
		} else {
			\file_put_contents("{$this->homedir}/{$key}.crt", $certificate);
//			\unlink("{$this->homedir}/certificates.json");
			$this->certificates(true);
			\X2Mail\Engine\Log::debug('OpenSSL', "certificate {$key} imported");
		}
		return true;
	}

	public function certificates(bool $force = false) : array
	{
		$cacheFile = "{$this->homedir}/certificates.json";
		$result = (!$force && \file_exists($cacheFile))
			? \json_decode(\file_get_contents($cacheFile), true)
			: null;
		if (!\is_array($result)) {
			$keys = [];
			foreach (\glob("{$this->homedir}/*.key") as $file) {
				$data = \file_get_contents($file);
				// Can't check ENCRYPTED PRIVATE KEY
				if (\str_contains($data, '-----BEGIN PRIVATE KEY-----')) {
					$keys[] = [\basename($file), $data];
				}
			}
			$result = [];
			foreach (\glob("{$this->homedir}/*.crt") as $file) {
				$filename = \basename($file);
				$certificate = \file_get_contents($file);
				$data = \openssl_x509_parse($certificate);
				if ($data) {
					$key = \str_replace(':', '', $data['extensions']['subjectKeyIdentifier'] ?? $data['hash']);
					if ("{$key}.crt" != $filename && \rename("{$this->homedir}/{$filename}", "{$this->homedir}/{$key}.crt")) {
						$filename = "{$key}.crt";
					}
//					Use $data['extensions']['authorityKeyIdentifier'] to bundle parent certificate
					$short = [
						'file' => $filename,
						'id' => $key,
						'CN' => $data['subject']['CN'],
						'emailAddress' => $data['subject']['emailAddress'],
//						'validTo' => \gmdate('Y-m-d\\TH:i:s\\Z', $data['validTo_time_t']),
						'validTo_time_t' => $data['validTo_time_t'],
						'smimesign' => false,
						'smimeencrypt' => false,
						'privateKey' => null // not found or encrypted
					];
					foreach ($data['purposes'] as $purpose) {
						if ('smimesign' === $purpose[2] || 'smimeencrypt' === $purpose[2]) {
							// [general availability, tested purpose]
							$short[$purpose[2]] = $purpose[0] || $purpose[1];
						}
					}
					foreach ($keys as $key) {
						if (\openssl_x509_check_private_key($certificate, $key[1])) {
							$short['privateKey'] = $key[0];
							break;
						}
					}
					$result[] = $short;
				} else {
					\X2Mail\Engine\Log::error('OpenSSL', "parse({$file}): " . \openssl_error_string());
				}
			}
			\file_put_contents($cacheFile, \json_encode($result));
		}

		return $result;
	}

	public function privateKeys() : array
	{
//		\glob("{$this->homedir}/*.key");
		return [];
	}

	public static function isSupported() : bool
	{
		return \defined('PKCS7_DETACHED');
	}

	public function setCertificate(/*OpenSSLCertificate|string*/$certificate)
	{
		$this->certificate = \openssl_x509_read($certificate);
		if (!$this->certificate) {
			throw new \RuntimeException('OpenSSL x509: ' . \openssl_error_string());
		}
		if ($this->privateKey && !\openssl_x509_check_private_key($this->certificate, $this->privateKey)) {
			throw new \RuntimeException('OpenSSL x509: ' . \openssl_error_string());
		}
	}

	public function setPrivateKey(/*OpenSSLAsymmetricKey|string*/$privateKey,
		?\X2Mail\Engine\SensitiveString $passphrase = null
	) : void
	{
		$this->privateKey = \openssl_pkey_get_private($privateKey, $passphrase);
		if (!$this->privateKey) {
			throw new \RuntimeException('OpenSSL setPrivateKey: ' . \openssl_error_string());
		}
		if ($this->certificate && !\openssl_x509_check_private_key($this->certificate, $this->privateKey)) {
			throw new \RuntimeException('OpenSSL setPrivateKey: ' . \openssl_error_string());
		}
	}

	public function exportPrivateKey(?\X2Mail\Engine\SensitiveString $passphrase = null): string
	{
		if (!$this->privateKey) {
			throw new \RuntimeException('OpenSSL exportPrivateKey: key not loaded');
		}
		$options = [
			'encrypt_key' => true,
			'encrypt_key_cipher' => \OPENSSL_CIPHER_AES_256_CBC
		];
		$output = '';
		if (!\openssl_pkey_export($this->privateKey, $output, $passphrase/*, $options*/)) {
			throw new \RuntimeException('OpenSSL exportPrivateKey: ' . \openssl_error_string());
		}
		return $output;
	}

/*
	public function asn1parse(/*string|Temporary* / $input) : ?string
	{
		if (\is_string($input)) {
			$tmp = new Temporary('smimein-');
			if (!$tmp->putContents($input)) {
				return null;
			}
			$input = $tmp;
		}
		`openssl asn1parse -in $input->filename()`
	}
*/
	public function decrypt(/*string|Temporary*/ $input) : ?string
	{
		if (\is_string($input)) {
			$tmp = new Temporary('smimein-');
			if (!$tmp->putContents($input)) {
				return null;
			}
			$input = $tmp;
		}
		$output = new Temporary('smimeout-');
		if (!\openssl_pkcs7_decrypt(
			$input->filename(),
			$output->filename(),
			$this->certificate,
			$this->privateKey
		)) {
			throw new \RuntimeException('OpenSSL decrypt: ' . \openssl_error_string());
		}
		return $output->getContents();
	}

	public function encrypt(/*string|Temporary*/$input, array $certificates) : ?string
	{
		if (\is_string($input)) {
			$tmp = new Temporary('smimein-');
			if (!$tmp->putContents($input)) {
				return null;
			}
			$input = $tmp;
		}
		$output = new Temporary('smimeout-');
		$flags = \defined('PKCS7_NOOLDMIMETYPE') ? \PKCS7_NOOLDMIMETYPE : 0;
		if (!\openssl_pkcs7_encrypt(
			$input->filename(),
			$output->filename(),
			$certificates,
			$this->headers,
			$flags,
			$this->cipher_algo
		)) {
			throw new \RuntimeException('OpenSSL encrypt: ' . \openssl_error_string());
		}

		/**
		 * Only fetch the body part
		 */
		$fp = $output->fopen();
		// Skip headers
		while (\trim(\fgets($fp)));
		// Fetch the body
		$encrypted = '';
		do {
			$line = \fgets($fp);
			if (!\trim($line)) {
				return $encrypted;
			}
			$encrypted .= $line;
		} while (true);

		return $encrypted;
	}

	public function sign(/*string|Temporary*/$input, bool $detached = true)
	{
		if (\is_string($input)) {
			$tmp = new Temporary('smimein-');
			if (!$tmp->putContents($input)) {
				return null;
			}
			$input = $tmp;
		}
		$output = new Temporary('smimeout-');
		if (!\openssl_pkcs7_sign(
			$input->filename(),
			$output->filename(),
			$this->certificate,
			$this->privateKey,
			$this->headers,
			$detached ? \PKCS7_DETACHED | \PKCS7_BINARY : 0, // | PKCS7_NOCERTS | PKCS7_NOATTR
			$this->untrusted_certificates_filename
		)) {
			throw new \RuntimeException('OpenSSL sign: ' . \openssl_error_string());
		}

		/**
		 * Only fetch the signed body part
		 */
		$fp = $output->fopen();
		$micalg = '';
		while (!\feof($fp)) {
			$line = \fgets($fp);
/*
				if (!$micalg && \str_contains($line, 'Content-Type: multipart/signed')) {
					\preg_match('/micalg="([^"+])"/', $line, $match);
					$micalg = $match[1];
				}
*/
				if (($detached && \str_contains($line, 'Content-Type: application/x-pkcs7-signature'))
				 || (!$detached && \str_contains($line, 'Content-Type: application/x-pkcs7-mime'))
				) {
					// Skip headers
					while (\trim(\fgets($fp)));
					// Fetch the body
					$signature = '';
					do {
						$line = \fgets($fp);
						if (!\trim($line)) {
							return $signature;
						}
						$signature .= $line;
					} while (true);
				}
		}

		throw new \RuntimeException('OpenSSL sign: failed to find p7s');
	}

	/**
	 * $opaque = true, when the message is not detached
	 */
	public function verify(/*string|Temporary*/$input, ?string $signers_certificates_filename = null, bool $opaque = false)
	{
		if (\is_string($input)) {
			$tmp = new Temporary('smimein-');
			if (!$tmp->putContents($input)) {
				return null;
			}
			$opaque |= \str_contains($input, 'application/pkcs7-mime') || \str_contains($input, 'application/x-pkcs7-mime');
			$input = $tmp;
		}
		$output = $opaque ? new Temporary('smimeout-') : null;

		// Extract the signer certificate(s) so we can report identity + trust
		// instead of a blanket "verified" (security review S2).
		$signersTmp = new Temporary('smimesig-');

		// PKCS7_NOVERIFY: openssl still checks the signature against the
		// embedded cert (cryptographic validity), but we decide *trust*
		// ourselves below via the user's certificate store (TOFU). Never
		// report "verified" purely because the signature is self-consistent.
		$valid = true === \openssl_pkcs7_verify(
			$input->filename(),
			\PKCS7_NOVERIFY | \PKCS7_NOCHAIN,
			$signers_certificates_filename ?: $signersTmp->filename(),
			$ca_info = [],
			$this->untrusted_certificates_filename,
			$output ? $output->filename() : null,
			$output_filename = null
		);

		$signers = $valid ? $this->parseSigners($signersTmp->getContents() ?: '') : [];

		return [
			'success' => $valid,
			'trusted' => $valid && $this->signersAreKnown($signers),
			'signers' => $signers,
			'body' => $output ? $output->getContents() : null,
		];
	}

	/**
	 * Parse signer identities out of the extracted PEM bundle.
	 *
	 * @return list<array{email: string, cn: string, fingerprint: string}>
	 */
	private function parseSigners(string $pem) : array
	{
		$out = [];
		if (\preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $m)) {
			foreach ($m[0] as $certPem) {
				$data = \openssl_x509_parse($certPem);
				if (!$data) {
					continue;
				}
				$out[] = [
					'email' => (string) ($data['subject']['emailAddress'] ?? ''),
					'cn' => (string) ($data['subject']['CN'] ?? ''),
					'fingerprint' => \openssl_x509_fingerprint($certPem, 'sha256') ?: '',
				];
			}
		}
		return $out;
	}

	/**
	 * TOFU trust: every signer cert must already be present in the user's
	 * store (by SHA-256 fingerprint). An unknown signer is reported untrusted.
	 *
	 * @param list<array{email: string, cn: string, fingerprint: string}> $signers
	 */
	private function signersAreKnown(array $signers) : bool
	{
		if (!$signers) {
			return false;
		}
		$known = [];
		foreach (\glob("{$this->homedir}/*.crt") ?: [] as $file) {
			$pem = \file_get_contents($file);
			$fp = $pem ? \openssl_x509_fingerprint($pem, 'sha256') : false;
			if ($fp) {
				$known[$fp] = true;
			}
		}
		foreach ($signers as $signer) {
			if ('' === $signer['fingerprint'] || empty($known[$signer['fingerprint']])) {
				return false;
			}
		}
		return true;
	}
}
