<?php
/**
 * This file is part of the holonet filesystem transaction package
 * (c) Matthias Lantsch.
 *
 * @license http://opensource.org/licenses/gpl-license.php  GNU Public License
 * @author  Matthias Lantsch <matthias.lantsch@bluewin.ch>
 */

namespace holonet\fstransact;

use Throwable;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;

/**
 * Wrapper around the symfony filesystem class to use a "covex-nn/vfs" custom stream wrapper
 * that performs all filesystem operations on a virtual transaction filesystem until commit()
 * The class will only register the custom stream wrapper if a transaction is started,
 * otherwise all operations will be done directly on the real filesystem.
 */
class TransactionalFilesystem extends Filesystem {
	public const DEFAULT_PROTOCOL_NAME = 'vfs-transact';

	/**
	 * @var string $protocolName The used name (scheme) on which the custom stream wrapper is registered on
	 */
	public $protocolName;

	/**
	 * @var string $baseDir Directory to base the virtual filesystem on
	 */
	private $baseDir;

	/**
	 * @var int $transactionCounter Transaction counter allows us to encapsulate transactions within each other
	 */
	private $transactionCounter = 0;

	/**
	 * If $baseDir is not given, the instance will create the virtual filesystem based on the "root" directory above getcwd()
	 * For linux-type systems, this should always be '/'
	 * On windows, this will be the drive letter for the mounted drive the script is running on
	 * This means that on windows, there will be problems using this class to copy between multiple drives.
	 * @param string|null $baseDir Base directory of the vfs (defaults to root dir above getcwd())
	 * @param string|null $protocolName Override for the default wrapper name for when you need to separate instances of this class running
	 */
	public function __construct(string $baseDir = null, string $protocolName = null) {
		if ($baseDir === null) {
			$baseDir = $this->discoverRootDir();
		}

		if ($protocolName === null) {
			$protocolName = static::DEFAULT_PROTOCOL_NAME;
		}

		$this->baseDir = $this->normalisePath($baseDir);
		$this->protocolName = $protocolName;
	}

	public function __destruct() {
		$this->rollback();
	}

	/**
	 * {@inheritdoc}
	 */
	public function appendToFile($filename, $content): void {
		if ($this->inTransaction()) {
			//dirname() doesn't work with files in the vfs root directory
			//since we bind to that during a transaction we can safely skip the writable checks of the parent implementation
			if (dirname($filename) === $this->baseDir) {
				if (false === @file_put_contents($this->fixpath($filename), $content, FILE_APPEND)) {
					throw new IOException(sprintf('Failed to write file "%s".', $filename), 0, null, $filename);
				}

				return;
			}
		}

		parent::appendToFile($this->fixpath($filename), $content);
	}

	/**
	 * Read a file from the virtual file system.
	 * @param string $filename File to be read
	 * @return string contents of the file
	 */
	public function catFile(string $filename): string {
		if (!$this->exists($filename) || !is_readable($this->fixpath($filename))) {
			throw new IOException("Could not read / access '{$filename}'");
		}

		try {
			$contents = file_get_contents($this->fixpath($filename));
		} catch (Throwable $e) {
			throw new IOException("Could not file_get_contents() '{$filename}'", (int)$e->getCode(), $e);
		}

		if ($contents === false) {
			throw new IOException("Could not file_get_contents() '{$filename}'");
		}

		return $contents;
	}

	/**
	 * {@inheritdoc}
	 */
	public function chgrp($files, $group, $recursive = false): void {
		parent::chown($this->fixpaths($files), $group, $recursive);
	}

	/**
	 * {@inheritdoc}
	 */
	public function chmod($files, $mode, $umask = 0000, $recursive = false): void {
		parent::chmod($this->fixpaths($files), $mode, $umask, $recursive);
	}

	/**
	 * {@inheritdoc}
	 */
	public function chown($files, $user, $recursive = false): void {
		parent::chown($this->fixpaths($files), $user, $recursive);
	}

	/**
	 * commits a running transaction on the filesystem
	 * uses an internal counter to allow for transaction encapsulation.
	 * @return bool true on success
	 */
	public function commit(): bool {
		$this->transactionCounter--;

		if ($this->transactionCounter === 0) {
			if (!\Covex\Stream\FileSystem::commit($this->protocolName)) {
				throw new IOException('Failed to commit vfs changes to the real filesystem');
			}

			return \Covex\Stream\FileSystem::unregister($this->protocolName);
		}

		return $this->transactionCounter >= 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function copy($originFile, $targetFile, $overwriteNewerFiles = false): void {
		parent::copy(
			$this->fixpath($originFile),
			$this->fixpath($targetFile),
			$overwriteNewerFiles
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function dumpFile($filename, $content): void {
		if ($this->inTransaction()) {
			//dirname() doesn't work with files in the vfs root directory
			//since we bind to that during a transaction we can safely skip the writable checks of the parent implementation
			if (dirname($filename) === $this->baseDir) {
				if (false === @file_put_contents($this->fixpath($filename), $content)) {
					throw new IOException(sprintf('Failed to write file "%s".', $filename), 0, null, $filename);
				}

				return;
			}
		}

		parent::dumpFile($this->fixpath($filename), $content);
	}

	/**
	 * {@inheritdoc}
	 */
	public function exists($files) {
		return parent::exists($this->fixpaths($files));
	}

	/**
	 * {@inheritdoc}
	 */
	public function hardlink($originFile, $targetFiles): void {
		parent::hardlink($this->fixpath($originFile), $this->fixpaths($targetFiles));
	}

	/**
	 * @return bool true if we are in a transaction at the moment
	 */
	public function inTransaction(): bool {
		return $this->transactionCounter > 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function mirror($originDir, $targetDir, \Traversable $iterator = null, $options = array()): void {
		parent::mirror($this->fixpath($originDir), $this->fixpath($targetDir));
	}

	/**
	 * {@inheritdoc}
	 */
	public function mkdir($dirs, $mode = 0777): void {
		parent::mkdir($this->fixpaths($dirs));
	}

	/**
	 * {@inheritdoc}
	 */
	public function readlink($path, $canonicalize = false): void {
		parent::readlink($this->fixpath($path), $canonicalize);
	}

	/**
	 * {@inheritdoc}
	 */
	public function remove($files): void {
		parent::remove($this->fixpaths($files));
	}

	/**
	 * {@inheritdoc}
	 */
	public function rename($origin, $target, $overwrite = false): void {
		if ($this->inTransaction() && $overwrite && $this->exists($target)) {
			//weird bug with rename and existing files while using a custom stream wrapper
			//this is not an issue since we are on a virtual file system anyway
			$this->remove($target);
		}
		parent::rename($this->fixpath($origin), $this->fixpath($target), $overwrite);
	}

	/**
	 * does a rollback of the running transaction on the filesystem
	 * uses an internal counter to allow for transaction encapsulation.
	 * @return bool true or false on success or not
	 */
	public function rollback(): bool {
		if ($this->transactionCounter === 1) {
			$this->transactionCounter = 0;

			return \Covex\Stream\FileSystem::unregister($this->protocolName);
		}

		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function symlink($originDir, $targetDir, $copyOnWindows = false): void {
		parent::symlink($this->fixpath($originDir), $this->fixpath($targetDir), $copyOnWindows);
	}

	/**
	 * {@inheritdoc}
	 */
	public function touch($files, $time = null, $atime = null): void {
		parent::touch($this->fixpaths($files), $time, $atime);
	}

	/**
	 * starts a transaction on the filesystem
	 * uses an internal counter to allow for transaction encapsulation.
	 * @return bool true or false on success or not
	 */
	public function transaction(): bool {
		$this->transactionCounter++;

		if ($this->transactionCounter === 1) {
			return \Covex\Stream\FileSystem::register($this->protocolName, $this->baseDir);
		}

		return $this->transactionCounter >= 0;
	}

	private function discoverRootDir(): string {
		$rootdir = getcwd();
		while (dirname($rootdir) !== $rootdir) {
			$rootdir = dirname($rootdir);
		}

		return $rootdir;
	}

	/**
	 * If we are in a transaction, all paths must be prepended with the custom file protocol wrapper
	 * in order to get it from the vfs.
	 */
	private function fixpath(string $path): string {
		$path = $this->normalisePath($path);

		//problem with dirname() and file in the vfs root directory
		if ($path === "{$this->protocolName}:") {
			return "{$path}///";
		}

		//never fix the path twice
		if (mb_strpos($path, $this->protocolName) === 0) {
			return $path;
		}

		if ($this->inTransaction()) {
			if (mb_substr($path, 0, mb_strlen($this->baseDir)) === $this->baseDir) {
				$path = mb_substr($path, mb_strlen($this->baseDir));
			} else {
				throw new InvalidArgumentException("Cannot access file path '{$path}' on the vfs, is not below base directory '{$this->baseDir}'");
			}

			$path = "{$this->protocolName}://{$path}";
		}

		return $path;
	}

	/**
	 * @see self::fixpath()
	 */
	private function fixpaths($paths) {
		$iterable = $this->toIterable($paths);
		$pathsArr = array();
		foreach ($iterable as $path) {
			$pathsArr[] = $this->fixpath($path);
		}

		return $pathsArr;
	}

	private function normalisePath(string $path) {
		return str_replace('\\', '/', $path);
	}

	private function toIterable($files): iterable {
		return \is_array($files) || $files instanceof \Traversable ? $files : array($files);
	}
}
