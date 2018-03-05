<?php
/**
 * Copyright (c) 2015 Victor Dubiniuk <victor.dubiniuk@gmail.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Files_Antivirus;

use OC\Files\View;
use OCA\Files_Antivirus\Activity\Provider;
use OCA\Files_Antivirus\AppInfo\Application;
use OCA\Files_Antivirus\Db\ItemMapper;
use OCP\Activity\IManager as ActivityManager;
use OCP\App;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\ILogger;

class Item implements IScannable{
	/**
	 * Scanned fileid (optional)
	 * @var int
	 */
	protected $id;
	
	/**
	 * File view
	 * @var \OC\Files\View
	 */
	protected $view;
	
	/**
	 * Path relative to the view
	 * @var string
	 */
	protected $path;
	
	/**
	 * file handle, user to read from the file
	 * @var resource
	 */
	protected $fileHandle;

	/**
	 * Is filesize match the size conditions
	 * @var bool
	 */
	protected $isValidSize;

	/** @var IL10N */
	private $l10n;

	/** @var AppConfig */
	private $config;

	/** @var ActivityManager */
	private $activityManager;

	/** @var ItemMapper */
	private $itemMapper;

	/** @var ILogger */
	private $logger;

	public function __construct(IL10N $l10n, View $view, $path, $id = null) {
		$this->l10n = $l10n;
		
		if (!is_object($view)){
			$this->logError('Can\'t init filesystem view.', $id, $path);
			throw new \RuntimeException();
		}
		
		if(!$view->file_exists($path)) {
			$this->logError('File does not exist.', $id, $path);
			throw new \RuntimeException();
		}

		$this->id = $id;
		if (is_null($id)){
			$this->id = $view->getFileInfo($path)->getId();
		}

		$this->view = $view;
		$this->path = $path;
		
		$this->isValidSize = $view->filesize($path) > 0;
		
		$application = new AppInfo\Application();
		$this->config = $application->getContainer()->query(AppConfig::class);
		$this->activityManager = \OC::$server->getActivityManager();
		$this->itemMapper = $application->getContainer()->query(ItemMapper::class);
		$this->logger = \OC::$server->getLogger();
	}
	
	/**
	 * Is this file good for scanning? 
	 * @return boolean
	 */
	public function isValid() {
		return !$this->view->is_dir($this->path) && $this->isValidSize;
	}
	
	/**
	 * Reads a file portion by portion until the very end
	 * @return string|boolean
	 */
	public function fread() {
		if (!$this->isValid()) {
			return false;
		}
		if (is_null($this->fileHandle)) {
			$this->getFileHandle();
		}
		
		if (!is_null($this->fileHandle) && !$this->feof()) {
			return fread($this->fileHandle, $this->config->getAvChunkSize());
		}
		return false;
	}
	
	/**
	 * Action to take if this item is infected
	 * @param Status $status
	 * @param boolean $isBackground
	 */
	public function processInfected(Status $status, $isBackground) {
		$infectedAction = $this->config->getAvInfectedAction();
		
		$shouldDelete = !$isBackground || ($isBackground && $infectedAction === 'delete');
		
		$message = $shouldDelete ? Provider::MESSAGE_FILE_DELETED : '';

		$activity = $this->activityManager->generateEvent();
		$activity->setApp(Application::APP_NAME)
			->setSubject(Provider::SUBJECT_VIRUS_DETECTED, [$this->path, $status->getDetails()])
			->setMessage($message)
			->setObject('', 0, $this->path)
			->setAffectedUser($this->view->getOwner($this->path))
			->setType(Provider::TYPE_VIRUS_DETECTED);
		$this->activityManager->publish($activity);

		if ($isBackground) {
			if ($shouldDelete) {
				$this->logError('Infected file deleted. ' . $status->getDetails());
				$this->deleteFile();
			} else {
				$this->logError('File is infected. '  . $status->getDetails());
			}
		} else {
			$this->logError('Virus(es) found: ' . $status->getDetails());
			//remove file
			$this->deleteFile();
			Notification::sendMail($this->path);
			$message = $this->l10n->t(
						"Virus detected! Can't upload the file %s", 
						[basename($this->path)]
			);
			\OCP\JSON::error(['data' => ['message' => $message]]);
			exit();
		}
	}

	/**
	 * Action to take if this item status is unclear
	 * @param Status $status
	 * @param boolean $isBackground
	 */
	public function processUnchecked(Status $status, $isBackground) {
		//TODO: Show warning to the user: The file can not be checked
		$this->logError('Not Checked. ' . $status->getDetails());
	}
	
	/**
	 * Action to take if this item status is not infected
	 * @param Status $status
	 * @param boolean $isBackground
	 */
	public function processClean(Status $status, $isBackground) {
		if (!$isBackground) {
			return;
		}
		try {
			try {
				$item = $this->itemMapper->findByFileId($this->id);
				$this->itemMapper->delete($item);
			} catch (DoesNotExistException $e) {
				//Just ignore
			}

			$item = new \OCA\Files_Antivirus\Db\Item();
			$item->setFileid($this->id);
			$item->setCheckTime(time());
			$this->itemMapper->insert($item);
		} catch(\Exception $e) {
			$this->logger->error(__METHOD__.', exception: '.$e->getMessage(), ['app' => 'files_antivirus']);
		}
	}

	/**
	 * Check if the end of file is reached
	 * @return boolean
	 */
	private function feof() {
		$isDone = feof($this->fileHandle);
		if ($isDone) {
			$this->logDebug('Scan is done');
			fclose($this->fileHandle);
			$this->fileHandle = null;
		}
		return $isDone;
	}
	
	/**
	 * Opens a file for reading
	 * @throws \RuntimeException
	 */
	private function getFileHandle() {
		$fileHandle = $this->view->fopen($this->path, 'r');
		if ($fileHandle === false) {
			$this->logError('Can not open for reading.', $this->id, $this->path);
			throw new \RuntimeException();
		}

		$this->logDebug('Scan started');
		$this->fileHandle = $fileHandle;
	}

	/**
	 * Delete infected file
	 */
	private function deleteFile() {
		//prevent from going to trashbin
		if (App::isEnabled('files_trashbin')) {
			\OCA\Files_Trashbin\Storage::preRenameHook([]);
		}
		$this->view->unlink($this->path);
		if (App::isEnabled('files_trashbin')) {
			\OCA\Files_Trashbin\Storage::postRenameHook([]);
		}
	}
	
	/**
	 * @param string $message
	 */
	public function logDebug($message) {
		$extra = ' File: ' . $this->id 
				. 'Account: ' . $this->view->getOwner($this->path) 
				. ' Path: ' . $this->path;
		$this->logger->debug($message . $extra, ['app' => 'files_antivirus']);
	}
	
	/**
	 * @param string $message
	 * @param int $id optional
	 * @param string $path optional
	 */
	public function logError($message, $id=null, $path=null) {
		$ownerInfo = is_null($this->view) ? '' : 'Account: ' . $this->view->getOwner($path);
		$extra = ' File: ' . (is_null($id) ? $this->id : $id)
				. $ownerInfo 
				. ' Path: ' . (is_null($path) ? $this->path : $path);
		$this->logger->error($message . $extra, ['app' => 'files_antivirus']);
		);
	}
}
