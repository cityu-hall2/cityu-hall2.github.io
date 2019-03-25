<?php
/**
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Julius Haertl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
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
 *
 */

namespace OC\Core\Controller;

use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ILogger;
use \OCP\IURLGenerator;
use \OCP\IRequest;
use \OCP\IL10N;
use \OCP\IConfig;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Security\ISecureRandom;
use \OC_Defaults;
use OCP\Security\StringUtils;

/**
 * Class LostController
 *
 * Successfully changing a password will emit the post_passwordReset hook.
 *
 * @package OC\Core\Controller
 */
class LostController extends Controller {

	/** @var IURLGenerator */
	protected $urlGenerator;
	/** @var IUserManager */
	protected $userManager;
	// FIXME: Inject a non-static factory of OC_Defaults for better unit-testing
	/** @var OC_Defaults */
	protected $defaults;
	/** @var IL10N */
	protected $l10n;
	/** @var string */
	protected $from;
	/** @var bool */
	protected $isDataEncrypted;
	/** @var IConfig */
	protected $config;
	/** @var ISecureRandom */
	protected $secureRandom;
	/** @var IMailer */
	protected $mailer;
	/** @var ITimeFactory */
	protected $timeFactory;
	/** @var ILogger */
	protected $logger;

	/**
	 * @param string $appName
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param IUserManager $userManager
	 * @param OC_Defaults $defaults
	 * @param IL10N $l10n
	 * @param IConfig $config
	 * @param ISecureRandom $secureRandom
	 * @param string $from
	 * @param string $isDataEncrypted
	 * @param IMailer $mailer
	 * @param ITimeFactory $timeFactory
	 * @param ILogger $logger
	 */
	public function __construct($appName,
								IRequest $request,
								IURLGenerator $urlGenerator,
								IUserManager $userManager,
								OC_Defaults $defaults,
								IL10N $l10n,
								IConfig $config,
								ISecureRandom $secureRandom,
								$from,
								$isDataEncrypted,
								IMailer $mailer,
								ITimeFactory $timeFactory,
								ILogger $logger) {
		parent::__construct($appName, $request);
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
		$this->defaults = $defaults;
		$this->l10n = $l10n;
		$this->secureRandom = $secureRandom;
		$this->from = $from;
		$this->isDataEncrypted = $isDataEncrypted;
		$this->config = $config;
		$this->mailer = $mailer;
		$this->timeFactory = $timeFactory;
		$this->logger = $logger;
	}

	/**
	 * Someone wants to reset their password:
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 *
	 * @param string $token
	 * @param string $userId
	 * @return TemplateResponse
	 */
	public function resetform($token, $userId) {
		try {
			$this->checkPasswordResetToken($token, $userId);
		} catch (\Exception $e) {
			return new TemplateResponse(
				'core', 'error', [
					"errors" => array(array("error" => $e->getMessage()))
				],
				'guest'
			);
		}

		return new TemplateResponse(
			'core',
			'lostpassword/resetpassword',
			array(
				'link' => $this->urlGenerator->linkToRouteAbsolute('core.lost.setPassword', array('userId' => $userId, 'token' => $token)),
			),
			'guest'
		);
	}

	/**
	 * @param string $userId
	 * @param string $userId
	 * @throws \Exception
	 */
	private function checkPasswordResetToken($token, $userId) {
		$user = $this->userManager->get($userId);

		$splittedToken = explode(':', $this->config->getUserValue($userId, 'owncloud', 'lostpassword', null));
		if(count($splittedToken) !== 2) {
			throw new \Exception($this->l10n->t('Couldn\'t reset password because the token is invalid'));
		}

		if ($splittedToken[0] < ($this->timeFactory->getTime() - 60*60*12) ||
			$user->getLastLogin() > $splittedToken[0]) {
			throw new \Exception($this->l10n->t('Couldn\'t reset password because the token is expired'));
		}

		if (!StringUtils::equals($splittedToken[1], $token)) {
			throw new \Exception($this->l10n->t('Couldn\'t reset password because the token is invalid'));
		}
	}

	/**
	 * @param $message
	 * @param array $additional
	 * @return array
	 */
	private function error($message, array $additional=array()) {
		return array_merge(array('status' => 'error', 'msg' => $message), $additional);
	}

	/**
	 * @return array
	 */
	private function success() {
		return array('status'=>'success');
	}

	/**
	 * @PublicPage
	 *
	 * @param string $user
	 * @return array
	 */
	public function email($user){
		// FIXME: use HTTP error codes
		try {
			$this->sendEmail($user);
		} catch (\Exception $e){
			return $this->error($e->getMessage());
		}

		return $this->success();
	}

	/**
	 * @PublicPage
	 * @param string $token
	 * @param string $userId
	 * @param string $password
	 * @param boolean $proceed
	 * @return array
	 */
	public function setPassword($token, $userId, $password, $proceed) {
		if ($this->isDataEncrypted && !$proceed) {
			return $this->error('', array('encryption' => true));
		}

		try {
			$this->checkPasswordResetToken($token, $userId);
			$user = $this->userManager->get($userId);

			if (!$user->setPassword($password)) {
				throw new \Exception();
			}

			\OC_Hook::emit('\OC\Core\LostPassword\Controller\LostController', 'post_passwordReset', array('uid' => $userId, 'password' => $password));

			$this->config->deleteUserValue($userId, 'owncloud', 'lostpassword');
			@\OC_User::unsetMagicInCookie();
		} catch (\Exception $e){
			return $this->error($e->getMessage());
		}

		return $this->success();
	}

	/**
	 * @param string $user
	 * @throws \Exception
	 */
	protected function sendEmail($user) {

		if ($this->userManager->userExists($user)) {

			$userObject = $this->userManager->get($user);
			$email = $userObject->getEMailAddress();

			if (!empty($email)) {
				$token = $this->secureRandom->generate(21,
					ISecureRandom::CHAR_DIGITS .
					ISecureRandom::CHAR_LOWER .
					ISecureRandom::CHAR_UPPER);
				$this->config->setUserValue($user, 'owncloud', 'lostpassword', $this->timeFactory->getTime() . ':' . $token);
				$link = $this->urlGenerator->linkToRouteAbsolute('core.lost.resetform', ['userId' => $user, 'token' => $token]);

				$tmpl = new \OC_Template('core', 'lostpassword/email');
				$tmpl->assign('link', $link);
				$msg = $tmpl->fetchPage();

				try {
					$message = $this->mailer->createMessage();
					$message->setTo([$email => $user]);
					$message->setSubject($this->l10n->t('%s password reset', [$this->defaults->getName()]));
					$message->setPlainBody($msg);
					$message->setFrom([$this->from => $this->defaults->getName()]);
					$this->mailer->send($message);
				} catch (\Exception $e) {
					throw new \Exception($this->l10n->t(
						'Couldn\'t send reset email. Please contact your administrator.'
					));
				}
			} else {
				$this->logger->error('Could not send reset email because there is no email address for this username. User: {user}', ['app' => 'core', 'user' => $user]);
			}
		} else {
			$this->logger->error('Could not send reset email because User does not exist. User: {user}', ['app' => 'core', 'user' => $user]);
		}
	}

}
