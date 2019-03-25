<?php
/**
 * @author Joas Schilling <nickvergessen@owncloud.com>
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

namespace OCA\Notifications;


use OCP\Notification\IApp;
use OCP\Notification\INotification;

class App implements IApp {
	/** @var Handler */
	protected $handler;

	public function __construct(Handler $handler) {
		$this->handler = $handler;
	}

	/**
	 * @param INotification $notification
	 * @return null
	 * @throws \InvalidArgumentException When the notification is not valid
	 * @since 8.2.0
	 */
	public function notify(INotification $notification) {
		$this->handler->add($notification);
	}

	/**
	 * @param INotification $notification
	 * @return int
	 * @since 8.2.0
	 */
	public function getCount(INotification $notification) {
		return $this->handler->count($notification);
	}

	/**
	 * @param INotification $notification
	 * @return null
	 * @since 8.2.0
	 */
	public function markProcessed(INotification $notification) {
		$this->handler->delete($notification);
	}
}
