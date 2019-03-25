<?php
/**
 * @author Björn Schießle <bjoern@schiessle.org>
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


use OCA\Files_Trashbin\AppInfo\Application;
use OCA\Files_Trashbin\Command\CleanUp;
use OCA\Files_Trashbin\Command\ExpireTrash;

$app = new Application();
$expiration = $app->getContainer()->query('Expiration');
$userManager = OC::$server->getUserManager();
$rootFolder = \OC::$server->getRootFolder();
$dbConnection = \OC::$server->getDatabaseConnection();

/** @var Symfony\Component\Console\Application $application */
$application->add(new CleanUp($rootFolder, $userManager, $dbConnection));
$application->add(new ExpireTrash($userManager, $expiration));
