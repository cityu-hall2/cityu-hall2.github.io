<?php

/**
 * @author Victor Dubiniuk <dubiniuk@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
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

namespace Owncloud\Updater\Command;

use Owncloud\Updater\Utils\Checkpoint;
use Owncloud\Updater\Utils\FilesystemHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Owncloud\Updater\Utils\OccRunner;
use Owncloud\Updater\Utils\ZipExtractor;

class ExecuteCoreUpgradeScriptsCommand extends Command {

	/**
	 * @var OccRunner $occRunner
	 */
	protected $occRunner;

	public function __construct($occRunner){
		parent::__construct();
		$this->occRunner = $occRunner;
	}

	protected function configure(){
		$this
				->setName('upgrade:executeCoreUpgradeScripts')
				->setDescription('execute core upgrade scripts [danger, might take long]');
	}

	protected function execute(InputInterface $input, OutputInterface $output){
		$locator = $this->container['utils.locator'];
		/** @var FilesystemHelper $fsHelper */
		$fsHelper = $this->container['utils.filesystemhelper'];
		$registry = $this->container['utils.registry'];
		$fetcher = $this->container['utils.fetcher'];
		/** @var Checkpoint $checkpoint */
		$checkpoint = $this->container['utils.checkpoint'];

		$installedVersion = implode('.', $locator->getInstalledVersion());
		$registry->set('installedVersion', $installedVersion);
		
		$feed = $registry->get('feed');

		if ($feed){
			$path = $fetcher->getBaseDownloadPath($feed);
			$fullExtractionPath = $locator->getExtractionBaseDir() . '/' . $feed->getVersion();

			if (file_exists($fullExtractionPath)){
				$fsHelper->removeIfExists($fullExtractionPath);
			}
			try{
				$fsHelper->mkdir($fullExtractionPath, true);
			} catch (\Exception $e){
					$output->writeln('Unable create directory ' . $fullExtractionPath);
					throw $e;
			}

			$output->writeln('Extracting source into ' . $fullExtractionPath);
			$extractor = new ZipExtractor($path, $fullExtractionPath, $output);

			try{
				$extractor->extract();
			} catch (\Exception $e){
				$output->writeln('Extraction has been failed');
				$fsHelper->removeIfExists($locator->getExtractionBaseDir());
				throw $e;
			}

			$tmpDir = $locator->getExtractionBaseDir() . '/' . $installedVersion;
			$fsHelper->removeIfExists($tmpDir);
			$fsHelper->mkdir($tmpDir);
			$oldSourcesDir = $locator->getOwncloudRootPath();
			$newSourcesDir = $fullExtractionPath . '/owncloud';

			foreach ($locator->getRootDirContent() as $dir){
				$this->getApplication()->getLogger()->debug('Replacing ' . $dir);
				$fsHelper->tripleMove($oldSourcesDir, $newSourcesDir, $tmpDir, $dir);
			}
			
			$fsHelper->copyr($tmpDir . '/config/config.php', $oldSourcesDir . '/config/config.php');

			//Remove old apps
			$appDirectories = $fsHelper->scandirFiltered($oldSourcesDir . '/apps');
			foreach ($appDirectories as $appDirectory){
				$fsHelper->rmdirr($oldSourcesDir . '/apps/' . $appDirectory);
			}

			//Put new shipped apps
			$newAppsDir = $fullExtractionPath . '/owncloud/apps';
			$newAppsList = $fsHelper->scandirFiltered($newAppsDir);
			foreach ($newAppsList as $appId){
				$output->writeln('Copying the application ' . $appId);
				$fsHelper->copyr($newAppsDir . '/' . $appId, $locator->getOwnCloudRootPath() . '/apps/' . $appId, false);
			}
			
			try {
				$plain = $this->occRunner->run('upgrade');
				$output->writeln($plain);
			} catch (ProcessFailedException $e){
				$lastCheckpointId = $checkpoint->getLastCheckpointId();
				if ($lastCheckpointId){
					$lastCheckpointPath = $checkpoint->getCheckpointPath($lastCheckpointId);
					$fsHelper->copyr($lastCheckpointPath . '/apps', $oldSourcesDir . '/apps', false);
				}
				if ($e->getProcess()->getExitCode() != 3){
					throw ($e);
				}
			}
		}
	}
}
