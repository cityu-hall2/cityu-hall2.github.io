<?php

/**
 * ownCloud - Template Editor
 *
 * @author Jörn Dreyer
 * @copyright 2014 Jörn Dreyer <jfd@owncloud.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\TemplateEditor;

use OCP\Files\NotPermittedException;
use OC\AppFramework\Middleware\Security\SecurityException;
use OCA\TemplateEditor\Http\MailTemplateResponse;

class MailTemplate extends \OC_Template {

	/** @var string */
	private $path;

	/** @var string */
	private $theme;

	/** @var array */
	private $editableThemes;

	/** @var array */
	private $editableTemplates;

	/**
	 * @param string string $theme
	 * @param string string $path
	 */
	public function __construct($theme, $path) {
		$this->theme = $theme;
		$this->path = $path;

		//determine valid theme names
		$this->editableThemes = self::getEditableThemes();
		//for now hard code the valid mail template paths
		$this->editableTemplates = self::getEditableTemplates();
	}
	
	/**
	 * @return \OCA\TemplateEditor\Http\MailTemplateResponse
	 * @throws \OC\AppFramework\Middleware\Security\SecurityException
	 */
	public function getResponse() {
		if($this->isEditable()) {
			list($app, $filename) = explode('/templates/', $this->path, 2);
			$name = substr($filename, 0, -4);
			list(, $template) = $this->findTemplate($this->theme, $app, $name);
			return new MailTemplateResponse($template);
		}
		throw new SecurityException('Template not editable.', 403);
	}

	public function renderContent() {
		if($this->isEditable()) {
			list($app, $filename) = explode('/templates/', $this->path, 2);
			$name = substr($filename, 0, -4);
			list(, $template) = $this->findTemplate($this->theme, $app, $name);
			\OC_Response::sendFile($template);
		} else {
			throw new SecurityException('Template not editable.', 403);
		}
	}

	public function isEditable() {
		if (isset($this->editableThemes[$this->theme])
			&& isset($this->editableTemplates[$this->path])
		) {
			return true;
		}
		return false;
	}

	public function setContent($data) {
		if($this->isEditable()) {
			//save default templates in default folder to overwrite core template
			$absolutePath = \OC::$SERVERROOT.'/themes/'.$this->theme.'/'.$this->path;
			$parent = dirname($absolutePath);
			if ( ! is_dir($parent) ) {
				if ( ! mkdir(dirname($absolutePath), 0777, true) ){
					throw new \Exception('Could not create directory.', 500);
				}
			}
			if ( $this->theme !== 'default' && is_file($absolutePath) ) {
				if ( ! copy($absolutePath, $absolutePath.'.bak') ){
					throw new \Exception('Could not overwrite template.', 500);
				}
			}
			//overwrite theme templates? use versions?
			return file_put_contents($absolutePath, $data);
		}
		throw new SecurityException('Template not editable.', 403);
	}

	public function reset(){
		if($this->isEditable()) {
			$absolutePath = \OC::$SERVERROOT.'/themes/'.$this->theme.'/'.$this->path;
			if ($this->theme === 'default') {
				//templates can simply be deleted in the themes folder
				if (unlink($absolutePath)) {
					return true;
				}
			} else {
				//if a bak file exists overwrite the template with it
				if (is_file($absolutePath.'.bak')) {
					if (rename($absolutePath.'.bak', $absolutePath)) {
						return true;
					}
				} else if (unlink($absolutePath)) {
					return true;
				}
			}
			return !file_exists($absolutePath);
		}
		throw new NotPermittedException('Template not editable.', 403);
	}

	/**
	 * @return array with available themes. consists of core and subfolders in the themes folder
	 */
	public static function getEditableThemes() {
		$themes = [];
		$theme = \OC::$server->getConfig()->getSystemValue('theme', null);
		if (!empty($theme)) {
			$themes[$theme] = true;
		}

		if ($handle = opendir(\OC::$SERVERROOT.'/themes')) {
			while (false !== ($entry = readdir($handle))) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				if (!is_null($theme) && $entry === $theme) {
					continue;
				}
				if (is_dir(\OC::$SERVERROOT.'/themes/'.$entry)) {
					$themes[$entry] = true;
				}
			}
			closedir($handle);
		}
		return $themes;
	}

	/**
	 * @return array with keys containing the path and values containing the name of a template
	 */
	public static function getEditableTemplates() {
		$l10n = \OC::$server->getL10NFactory()->get('templateeditor');
		$templates = array(
			'core/templates/mail.php' => $l10n->t('Sharing email - public link shares (HTML)'),
			'core/templates/altmail.php' => $l10n->t('Sharing email - public link shares (plain text fallback)'),
			'core/templates/internalmail.php' => $l10n->t('Sharing email (HTML)'),
			'core/templates/internalaltmail.php' => $l10n->t('Sharing email (plain text fallback)'),
			'core/templates/lostpassword/email.php' => $l10n->t('Lost password mail'),
			'settings/templates/email.new_user.php' => $l10n->t('New user email (HTML)'),
			'settings/templates/email.new_user_plain_text.php' => $l10n->t('New user email (plain text fallback)'),
		);

		if (\OCP\App::isEnabled('activity')) {
			$tmplPath = \OC_App::getAppPath('activity') . '/templates/email.notification.php';
			$path = substr($tmplPath, strlen(\OC::$SERVERROOT) + 1);
			$templates[$path] = $l10n->t('Activity notification mail');
		}

		return $templates;
	}
}
