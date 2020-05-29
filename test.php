<?php

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

require_once 'vendor\autoload.php';

class TransactionalFilesystem extends Filesystem {
    public const FS_PROTOCOL_NAME = 'transactional-vfs';
    private $baseDir;
    private $transactionCounter = 0;

    public function __construct(string $baseDir = null) {
        if($baseDir === null) {
            $baseDir = $this->discoverRootDir();
        }

        $this->baseDir = $baseDir;
    }

    public function copy($originFile, $targetFile, $overwriteNewerFiles = false) {
        parent::copy(
            $this->fixpath($originFile),
            $this->fixpath($targetFile),
            $overwriteNewerFiles
        );
    }

    public function mkdir($dirs, $mode = 0777) {
        parent::mkdir($this->fixpaths($dirs));
    }

    public function exists($files)
    {
        return parent::exists($this->fixpaths($files));
    }

    public function touch($files, $time = null, $atime = null)
    {
        parent::touch($this->fixpaths($files), $time, $atime);
    }

    public function remove($files)
    {
        parent::remove($this->fixpaths($files));
    }

    public function chown($files, $user, $recursive = false)
    {
        parent::chown($this->fixpaths($files), $user, $recursive);
    }

    public function inTransaction(): bool {
        return ($this->transactionCounter > 0);
    }

    public function chgrp($files, $group, $recursive = false)
    {
        parent::chown($this->fixpaths($files), $group, $recursive);
    }

    public function rename($origin, $target, $overwrite = false)
    {
        //weird bug with rename and existing files while using a custom stream wrapper
        if($overwrite && $this->exists($target)) {
            $this->remove($target);
        }
        parent::rename($this->fixpath($origin), $this->fixpath($target), $overwrite);
    }

    public function chmod($files, $mode, $umask = 0000, $recursive = false)
    {
        parent::chmod($this->fixpaths($files), $mode, $umask, $recursive);
    }

    public function symlink($originDir, $targetDir, $copyOnWindows = false)
    {
        parent::symlink($this->fixpath($originDir), $this->fixpath($targetDir), $copyOnWindows);
    }

    public function hardlink($originFile, $targetFiles)
    {
        parent::hardlink($this->fixpath($originFile), $this->fixpaths($targetFiles));
    }

    public function readlink($path, $canonicalize = false)
    {
        parent::readlink($this->fixpath($path), $canonicalize);
    }

    public function mirror($originDir, $targetDir, \Traversable $iterator = null, $options = [])
    {
        parent::mirror($this->fixpath($originDir), $this->fixpath($targetDir));
    }

    public function dumpFile($filename, $content)
    {
        parent::dumpFile($this->fixpath($filename), $content);
    }

    public function appendToFile($filename, $content)
    {
        parent::appendToFile($this->fixpath($filename), $content);
    }

    private function fixpath(string $path) {
        //never fix the path twice
        if(strpos($path, static::FS_PROTOCOL_NAME) === 0) {
            return $path;
        }

        if($this->inTransaction()) {
            if (substr($path, 0, strlen($this->baseDir)) == $this->baseDir) {
                $path = substr($path, strlen($this->baseDir));
            }
            $path = static::FS_PROTOCOL_NAME."://{$path}";
        }

        return $path;
    }

    private function discoverRootDir(): string {
        $rootdir = __DIR__;
        while (dirname($rootdir) !== $rootdir) {
            $rootdir = dirname($rootdir);
        }
        return $rootdir;
    }

    private function toIterable($files): iterable {
        return \is_array($files) || $files instanceof \Traversable ? $files : [$files];
    }

    private function fixpaths($paths) {
        $iterable = $this->toIterable($paths);
        foreach ($iterable as &$path) {
            $path = $this->fixpath($path);
        }
        return $iterable;
    }

    /**
     * commits a running transaction on the connection
     * uses an internal counter to allow for transaction encapsulation.
     * @return bool true on success
     */
    public function commit(): bool {
        $this->transactionCounter--;

        if ($this->transactionCounter === 0) {
            return Covex\Stream\FileSystem::commit(static::FS_PROTOCOL_NAME);
        }

        return $this->transactionCounter >= 0;
    }

    /**
     * does a rollback of the running transaction on the connection
     * uses an internal counter to allow for transaction encapsulation.
     * @return bool true or false on success or not
     */
    public function rollback(): bool {
        if ($this->transactionCounter === 1) {
            $this->transactionCounter = 0;

            return Covex\Stream\FileSystem::unregister(static::FS_PROTOCOL_NAME);
        }

        return false;
    }

    /**
     * starts a transaction on the connection
     * uses an internal counter to allow for transaction encapsulation.
     * @return bool true or false on success or not
     */
    public function transaction(): bool {
        $this->transactionCounter++;

        if ($this->transactionCounter === 1) {
            return Covex\Stream\FileSystem::register(static::FS_PROTOCOL_NAME, $this->baseDir);
        }

        return $this->transactionCounter >= 0;
    }
}

//$testfile = __DIR__."\\test.txt";
//
//$fs = new TransactionalFilesystem();
//$fs->transaction();
//
//echo $fs->exists($testfile) ? "yes" : "no";
//$fs->dumpFile($testfile, 'nönönönö');
//echo $fs->exists($testfile) ? "yes" : "no";
//fgets(STDIN);
//$fs->commit();
//
//die("stop");

//$rootdir = __DIR__."/tests/test_files";
//Covex\Stream\FileSystem::register("any-vfs-protocol", $rootdir);
//
//$testfile = "any-vfs-protocol:// /test.txt";
//
//dd(dirname($testfile));
//$fs = new Filesystem();
//
//echo file_exists($testfile) ? 'true' : 'false';
////file_put_contents($testfile, "hahaha");
//$fs->dumpFile($testfile, 'hahaha');
//echo file_exists($testfile) ? 'true' : 'false';
//echo file_get_contents($testfile);
//rename($testfile, "{$testfile}2");
//
//Covex\Stream\FileSystem::commit("any-vfs-protocol");
//
//Covex\Stream\FileSystem::unregister("any-vfs-protocol");

$baseDir = __DIR__."/tests/test_files";
$fs = new \holonet\fstransact\TransactionalFilesystem($baseDir);
$fs->dumpFile("{$baseDir}/testfile.txt", 'file1');
$fs->transaction();
$fs->commit();
