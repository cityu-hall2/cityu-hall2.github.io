<?php
/**
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 */

namespace OCA\ConfigReport;
use OC\IntegrityCheck\Checker;
use OC\OCSClient;
use OC\SystemConfig;
use OC\User\Manager;
use OCP\IAppConfig;

/**
 * @package OCA\ConfigReport\Report
 */
class ReportDataCollector {

	/**
	 * @var Checker
	 */
	private $integrityChecker;

	/**
	 * @var array
	 */
	private $users;

	/**
	 * @var Manager
	 */
	private $userManager;

	/**
	 * @var string
	 */
	private $licenseKey;

	/**
	 * @var array
	 */
	private $version;

	/**
	 * @var string
	 */
	private $versionString;

	/**
	 * @var string
	 */
	private $editionString;

	/**
	 * @var string
	 */
	private $displayName;

	/**
	 * @var \OC\SystemConfig
	 */
	private $systemConfig;

	/**
	 * @var OCSClient
	 */
	private $ocsClient;

	/**
	 * @var array
	 */
	private $apps;

	/**
	 * @var IAppConfig
	 */
	private $appConfig;

	/**
	 * @param Checker $integrityChecker
	 * @param array $users
	 * @param Manager $userManager
	 * @param string $licenseKey
	 * @param array $version
	 * @param string $versionString
	 * @param string $editionString
	 * @param string $displayName
	 * @param SystemConfig $systemConfig
	 * @param OCSClient $ocsClient
	 * @param IAppConfig $appConfig
	 */
	public function __construct(
		Checker $integrityChecker,
		array $users,
		Manager $userManager,
		$licenseKey,
		array $version,
		$versionString,
		$editionString,
		$displayName,
		SystemConfig $systemConfig,
		OCSClient $ocsClient,
		IAppConfig $appConfig
	) {
		$this->integrityChecker = $integrityChecker;
		$this->users = $users;
		$this->userManager = $userManager;
		$this->licenseKey = $licenseKey;

		$this->version = $version;
		$this->versionString = $versionString;
		$this->editionString = $editionString;
		$this->displayName = $displayName;

		$this->systemConfig = $systemConfig;
		$this->ocsClient = $ocsClient;
		$this->apps = \OC_App::listAllApps(false, false, $this->ocsClient);
		$this->appConfig = $appConfig;
	}


	/**
	 * @param int $options
	 * @param int $depth
	 * @return string
	 */
	public function getReportJson($options = JSON_PRETTY_PRINT, $depth = 512) {
		return json_encode($this->getReport(), $options, $depth);
	}

	/**
	 * @return array
	 */
	public function getReport() {
		// TODO: add l10n (unused right now)
		$l = \OC::$server->getL10N('config_report');

		return [
			'basic' => $this->getBasicDetailArray(),
			'integritychecker' => $this->getIntegrityCheckerDetailArray(),
			'apps' => $this->getAppsDetailArray(),
			'config' => $this->getSystemConfigDetailArray(),
			'phpinfo' => $this->getPhpInfoDetailArray()
		];
	}

	/**
	 * @return array
	 */
	private function getIntegrityCheckerDetailArray() {
		return [
			'passing' => $this->integrityChecker->hasPassedCheck(),
			'enabled' => $this->integrityChecker->isCodeCheckEnforced(),
			'result' => $this->integrityChecker->getResults(),
		];
	}

	/**
	 * @return array
	 */
	private function getBasicDetailArray() {
		// Basic report data
		// TODO $homecount should be determined by \OC::$server->getUserManager()->search()
		// and then checking the lastLoginTime of each user object, leaving current impl intact
		$homeCount = 0;
		foreach($this->users as $uid) {
			if($this->userManager->get($uid)) {
				$homeCount++;
			}
		}

		return [
			'license key' => $this->licenseKey,
			'date' => date('r'),
			'ownCloud version' => implode('.', $this->version),
			'ownCloud version string' => $this->versionString,
			'ownCloud edition' => $this->editionString,
			'server OS' => PHP_OS,
			'server OS version' => php_uname(),
			'server SAPI' => php_sapi_name(),
			'webserver version' => $_SERVER['SERVER_SOFTWARE'],
			'hostname' => $_SERVER['HTTP_HOST'],
			'user count' => count($this->users),
			'user directories' => $homeCount,
			'logged-in user' => $this->displayName,
		];
	}

	/**
	 * @return array
	 */
	private function getSystemConfigDetailArray() {
		$keys = $this->systemConfig->getKeys();
		$result = array();
		foreach ($keys as $key) {
			$result[$key] = $this->systemConfig->getFilteredValue($key);
		}

		return $result;
	}

	/**
	 * @return array
	 */
	private function getAppsDetailArray() {
		// Get app data
		foreach($this->apps as &$app) {
			if($app['active']) {

				$appConfig = $this->appConfig->getValues($app['id'], false);
				foreach($appConfig as $key => $value) {
					if (stripos($key, 'password') !== FALSE) {
						$appConfig[$key] = \OCP\IConfig::SENSITIVE_VALUE;
					}
				}
				$app['appconfig'] = $appConfig;
			}
		}
		return $this->apps;
	}

	/**
	 * @return array
	 */
	private function getPhpInfoDetailArray() {
		$sensitiveServerConfigs = [
			'HTTP_COOKIE',
			'PATH',
			'Cookie',
			'include_path',
		];

		// Get the phpinfo, parse it, and record it (parts from http://www.php.net/manual/en/function.phpinfo.php#87463)
		ob_start();
		phpinfo(-1);

		$phpinfo = preg_replace(
			array('#^.*<body>(.*)</body>.*$#ms', '#<h2>PHP License</h2>.*$#ms',
				'#<h1>Configuration</h1>#', "#\r?\n#", "#</(h1|h2|h3|tr)>#", '# +<#',
				"#[ \t]+#", '#&nbsp;#', '#  +#', '# class=".*?"#', '%&#039;%',
				'#<tr>(?:.*?)" src="(?:.*?)=(.*?)" alt="PHP Logo" /></a>'
				. '<h1>PHP Version (.*?)</h1>(?:\n+?)</td></tr>#',
				'#<h1><a href="(?:.*?)\?=(.*?)">PHP Credits</a></h1>#',
				'#<tr>(?:.*?)" src="(?:.*?)=(.*?)"(?:.*?)Zend Engine (.*?),(?:.*?)</tr>#',
				"# +#", '#<tr>#', '#</tr>#'),
			array('$1', '', '', '', '</$1>' . "\n", '<', ' ', ' ', ' ', '', ' ',
				'<h2>PHP Configuration</h2>' . "\n" . '<tr><td>PHP Version</td><td>$2</td></tr>' .
				"\n" . '<tr><td>PHP Egg</td><td>$1</td></tr>',
				'<tr><td>PHP Credits Egg</td><td>$1</td></tr>',
				'<tr><td>Zend Engine</td><td>$2</td></tr>' . "\n" .
				'<tr><td>Zend Egg</td><td>$1</td></tr>', ' ', '%S%', '%E%'),
			ob_get_clean());

		$sections = explode('<h2>', strip_tags($phpinfo, '<h2><th><td>'));
		unset($sections[0]);

		$result = array();
		$sensitiveServerConfigs = array_flip($sensitiveServerConfigs);
		foreach ($sections as $section) {
			$n = substr($section, 0, strpos($section, '</h2>'));
			if ($n === 'PHP Variables') {
				continue;
			}
			preg_match_all(
				'#%S%(?:<td>(.*?)</td>)?(?:<td>(.*?)</td>)?(?:<td>(.*?)</td>)?%E%#',
				$section, $matches, PREG_SET_ORDER);
			foreach ($matches as $match) {
				if (isset($sensitiveServerConfigs[$match[1]])) {
					continue;
					// filter all key which contain 'password'
				}
				if(!isset($match[3])) {
					$value = isset($match[2]) ? $match[2] : null;
				}
				elseif($match[2] == $match[3]) {
					$value = $match[2];
				}
				else {
					$value = array_slice($match, 2);
				}
				$result[$n][$match[1]] = $value;
			}
		}

		return $result;
	}
}