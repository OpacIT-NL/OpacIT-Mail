<?php

require_once __DIR__ . '/app/libraries/opacit_mail/Engine/integrity.php';

$result = \opacit_mail\Engine\Integrity::phpVersion();
if ($result) {
	echo '<p style="color: red">[301] ' . $result . '</p>';
	exit(301);
}

$result = \opacit_mail\Engine\Integrity::phpExtensions();
if ($result) {
	echo '<p>[302] The following PHP extensions are not available in your PHP configuration!</p>';
	echo '<ul><li>' . \implode('</li>li><li>', $result) . '</li></ul>';
	exit(302);
}

if (defined('APP_VERSION')) {
	$sCheckName = 'delete_if_you_see_it_after_install';
	$sCheckFolder = APP_DATA_FOLDER_PATH.$sCheckName;
	$sCheckFilePath = APP_DATA_FOLDER_PATH.$sCheckName.'/'.$sCheckName.'.file';

	is_file($sCheckFilePath) && unlink($sCheckFilePath);
	is_dir($sCheckFolder) && rmdir($sCheckFolder);

	if (is_writable(dirname(APP_DATA_FOLDER_PATH))) {
		if (is_dir(APP_DATA_FOLDER_PATH)) {
			chmod(APP_DATA_FOLDER_PATH, 0700);
		} else {
			mkdir(APP_DATA_FOLDER_PATH, 0700, true);
		}
	}

	$sTest = '';
	switch (true)
	{
		case !is_dir(APP_DATA_FOLDER_PATH):
			$sTest = 'is_dir';
			error_log('Data folder permission error is_dir('.APP_DATA_FOLDER_PATH.')');
			break;
		case !is_readable(APP_DATA_FOLDER_PATH):
			$sTest = 'is_readable';
			error_log('Data folder permission error is_readable('.APP_DATA_FOLDER_PATH.')');
			break;
//		case !is_writable(APP_DATA_FOLDER_PATH):
//			$sTest = 'is_writable';
//			error_log('Data folder permission error is_writable('.APP_DATA_FOLDER_PATH.')');
//			break;
		case !mkdir($sCheckFolder, 0700):
			$sTest = 'mkdir';
			error_log("Data folder permission error mkdir({$sCheckFolder})");
			break;
		case false === file_put_contents($sCheckFilePath, time()):
			error_log("Data folder permission error file_put_contents({$sCheckFilePath})");
			$sTest = 'file_put_contents';
			break;
		case !unlink($sCheckFilePath):
			error_log("Data folder permission error unlink({$sCheckFilePath})");
			$sTest = 'unlink';
			break;
		case !rmdir($sCheckFolder):
			error_log("Data folder permission error rmdir({$sCheckFolder})");
			$sTest = 'rmdir';
			break;
	}

	if (!empty($sTest)) {
		echo "[202] {$sTest}() failed";
		exit(202);
	}

	unset($sCheckName, $sCheckFilePath, $sCheckFolder, $sTest);

	file_put_contents(APP_DATA_FOLDER_PATH.'INSTALLED', APP_VERSION);
	file_put_contents(APP_DATA_FOLDER_PATH.'index.html', 'Forbidden');
	file_put_contents(APP_DATA_FOLDER_PATH.'index.php', 'Forbidden');
	if (!is_file(APP_DATA_FOLDER_PATH.'.htaccess') && is_file(__DIR__ . '/app/.htaccess')) {
		copy(__DIR__ . '/app/.htaccess', APP_DATA_FOLDER_PATH.'.htaccess');
	}

	if (!is_dir(APP_PRIVATE_DATA)) {
		mkdir(APP_PRIVATE_DATA, 0700, true);
		file_put_contents(APP_PRIVATE_DATA.'.htaccess', 'Require all denied');
	} else if (is_dir(APP_PRIVATE_DATA.'cache')) {
		foreach (new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator(APP_PRIVATE_DATA.'cache', FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST) as $sName) {
				$sName->isDir() ? rmdir($sName) : unlink($sName);
		}
		clearstatcache();
	}

	foreach (array('configs', 'domains', 'plugins', 'storage') as $sName) {
		if (!is_dir(APP_PRIVATE_DATA.$sName)) {
			mkdir(APP_PRIVATE_DATA.$sName, 0700, true);
		}
	}

	if (defined('OPACIT_MAIL_UPDATE_PLUGINS')) {
		// Update plugins
		$asApi = !empty($_ENV['OPACIT_MAIL_INCLUDE_AS_API']);
		$_ENV['OPACIT_MAIL_INCLUDE_AS_API'] = true;
		$aList = \opacit_mail\Engine\Repository::getEnabledPackagesNames();
		foreach ($aList as $sId) {
			\opacit_mail\Engine\Repository::installPackage('plugin', $sId);
		}
		$_ENV['OPACIT_MAIL_INCLUDE_AS_API'] = $asApi;
	}

}
