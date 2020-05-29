<?php
/**
 * This file is part of the holonet filesystem transaction package
 * (c) Matthias Lantsch.
 *
 * @license http://opensource.org/licenses/gpl-license.php  GNU Public License
 * @author  Matthias Lantsch <matthias.lantsch@bluewin.ch>
 */

namespace holonet\fstransact\tests;

use Covex\Stream\FileSystem;
use holonet\fstransact\TransactionalFilesystem;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Filesystem\Exception\IOException;

class TransactionalFilesystemTest extends TestCase {
	private $testFileDirectory;

	protected function setUp(): void {
		$this->testFileDirectory = __DIR__.'/test_files';

		//setup work on the actual file system
		$fs = new \Symfony\Component\Filesystem\Filesystem();
		if($fs->exists($this->testFileDirectory)) {
			$fs->remove($this->testFileDirectory);
		}
		$fs->mkdir($this->testFileDirectory);

		$fs->dumpFile("{$this->testFileDirectory}/file0.txt", 'file0');
		$fs->mkdir("{$this->testFileDirectory}/dir1");
		$fs->dumpFile("{$this->testFileDirectory}/dir1/file1.txt", 'file1');
		$fs->mkdir("{$this->testFileDirectory}/dir1/dir2");
		$fs->dumpFile("{$this->testFileDirectory}/dir1/dir2/file2.txt", 'file2');
		$fs->mkdir("{$this->testFileDirectory}/dir3");
	}

	public static function tearDownAfterClass(): void {
		$fs = new \Symfony\Component\Filesystem\Filesystem();
		$fs->remove(__DIR__."/test_files");
	}

	public function testRegister(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$fs->transaction();

		$this->assertTrue(in_array($fs->protocolName, stream_get_wrappers()));

		$this->assertEquals(
			file_get_contents(__FILE__), $fs->catFile(__FILE__)
		);

		$fs->rollback();
		$this->assertFalse(in_array($fs->protocolName, stream_get_wrappers()));

		$fs->transaction();
		$this->assertTrue($fs->commit());
		$this->assertFalse(in_array($fs->protocolName, stream_get_wrappers()));
	}

	public function testTouch(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$fs->transaction();

		$this->assertFalse($fs->exists("{$this->testFileDirectory}/qwerty"));
		$fs->touch("{$this->testFileDirectory}/qwerty");
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/qwerty"));
		$this->assertFalse(file_exists("{$this->testFileDirectory}/qwerty"));
		$this->assertEquals('', $fs->catFile("{$this->testFileDirectory}/qwerty"));

		$fs->rollback();
	}

	public function testFiles(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$fs->transaction();
		$this->initFiles($fs);

		$this->assertTrue($fs->exists("{$this->testFileDirectory}/file1.txt"));
		$this->assertEquals('file1', $fs->catFile("{$this->testFileDirectory}/file1.txt"));
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/dir1"));
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/dir1/file2.txt"));
		$this->assertEquals('file2', $fs->catFile("{$this->testFileDirectory}/dir1/file2.txt"));
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/dir1/dir5"));
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/dir1/dir5/file5.txt"));
		$this->assertEquals('file5', $fs->catFile("{$this->testFileDirectory}/dir1/dir5/file5.txt"));

		$this->assertFalse($fs->exists("{$this->testFileDirectory}/dir1/dir3/file_not_exists.txt"));
		$this->assertFalse($fs->exists("{$this->testFileDirectory}/dir1/dir5/file_not_exists.txt"));

		$fs->rollback();
	}

	public function testMkdir(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$fs->transaction();

		$fs->mkdir("{$this->testFileDirectory}/dir1/dir2", 0777);
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/dir1/dir2"));

		$fs->rollback();
	}

	public function testUnlinkRmdir(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$this->initFiles($fs);
		$fs->transaction();

		//don't fail on existing dirs
		$fs->mkdir("{$this->testFileDirectory}/dir1/dir5");
		//don't fail on removing files that don't exist (they are removed after all)
		$fs->remove("{$this->testFileDirectory}/dir1/dir5/file_not_exists.txt");

		//file remove
		$fs->remove("{$this->testFileDirectory}/dir1/dir5/file5.txt");
		$this->assertFalse($fs->exists("{$this->testFileDirectory}/dir1/dir5/file5.txt"));
		$this->assertFileExists("{$this->testFileDirectory}/dir1/dir5/file5.txt");

		//implicit remove by removing parent dir
		$fs->remove("{$this->testFileDirectory}/dir1/dir5");
		$this->assertFalse($fs->exists("{$this->testFileDirectory}/dir1/dir5"));
		$this->assertFalse($fs->exists("{$this->testFileDirectory}/dir1/dir5/file6.txt"));
		$this->assertFileExists("{$this->testFileDirectory}/dir1/dir5/file6.txt");

		$this->assertTrue($fs->commit());
		$this->assertFileNotExists("{$this->testFileDirectory}/dir1/dir5/file5.txt");
		$this->assertFileNotExists("{$this->testFileDirectory}/dir1/dir5/file6.txt");
	}

	public function testRenameFileExists(): void
	{
		$fs = new TransactionalFilesystem(__DIR__);
		$this->initFiles($fs);
		$fs->transaction();

		$this->expectException(IOException::class);
		$this->expectExceptionMessage('Cannot rename because the target "vfs-transact:///test_files/dir1" already exists.');
		$fs->rename("{$this->testFileDirectory}/file1.txt", "{$this->testFileDirectory}/dir1");

		$fs->rollback();
	}

	public function testRenameFileExists2(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$this->initFiles($fs);
		$fs->transaction();

		$this->expectException(IOException::class);
		$this->expectExceptionMessage('Cannot rename "vfs-transact:///test_files/file2.txt" to "vfs-transact:///test_files/file3.txt".');
		$fs->rename("{$this->testFileDirectory}/file2.txt", "{$this->testFileDirectory}/file3.txt");

		$fs->rollback();
	}

	public function testRenameFile(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$this->initFiles($fs);
		$fs->transaction();

		$fs->rename("{$this->testFileDirectory}/file1.txt", "{$this->testFileDirectory}/file2.txt");
		$this->assertFalse($fs->exists("{$this->testFileDirectory}/file1.txt"));
		$this->assertFileExists("{$this->testFileDirectory}/file1.txt");
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/file2.txt"));
		$this->assertFileNotExists("{$this->testFileDirectory}/file2.txt");
		$this->assertEquals('file1', $fs->catFile("{$this->testFileDirectory}/file2.txt"));

		$this->assertTrue($fs->commit());
		$this->assertFileNotExists("{$this->testFileDirectory}/file1.txt");
		$this->assertFileExists("{$this->testFileDirectory}/file2.txt");
	}

	public function testRenameParentDir(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$this->initFiles($fs);
		$fs->transaction();

		$fs->rename("{$this->testFileDirectory}/dir1", "{$this->testFileDirectory}/dir2");
		$this->assertEquals('file5', $fs->catFile("{$this->testFileDirectory}/dir2/dir5/file5.txt"));
		$this->assertFalse($fs->exists("{$this->testFileDirectory}/dir1/dir5/file5.txt"));
		$this->assertFileExists("{$this->testFileDirectory}/dir1/dir5/file5.txt");

		$this->assertTrue($fs->commit());
		$this->assertFileNotExists("{$this->testFileDirectory}/dir1/dir5/file5.txt");
		$this->assertFileExists("{$this->testFileDirectory}/dir2/dir5/file5.txt");
	}

	public function testRenameMoveDir(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$this->initFiles($fs);
		$fs->dumpFile("{$this->testFileDirectory}/dir1/file7.txt", 'file7');
		$fs->transaction();

		$fs->rename("{$this->testFileDirectory}/dir1", "{$this->testFileDirectory}/dir2/dir5/dir7");
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/dir2/dir5/dir7/file7.txt"));
		$this->assertFileExists("{$this->testFileDirectory}/dir1/file7.txt");

		$this->assertTrue($fs->commit());
		$this->assertFileNotExists("{$this->testFileDirectory}/dir1/file7.txt");
		$this->assertFileExists("{$this->testFileDirectory}/dir2/dir5/dir7/file7.txt");
	}


	public function testRealFS(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$fs->transaction();

		$this->assertFalse($fs->exists("{$this->testFileDirectory}/dir1/dir2/dir3/file3.txt"));
		$this->assertFileNotExists($fs->exists("{$this->testFileDirectory}/dir1/dir2/dir3/dir4/file4.txt"));

		$this->assertTrue($fs->exists(__FILE__));
		$this->assertSame(file_get_contents(__FILE__), $fs->catFile(__FILE__));

		$fs->rollback();
	}

	public function testCommit1(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$fs->transaction();

		$this->assertTrue($fs->commit());
	}

	public function testCommit2(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$fs->transaction();

		$fs->dumpFile("{$this->testFileDirectory}/file0-0.txt", 'file0-0');
		$fs->remove("{$this->testFileDirectory}/file0-0.txt");
		$fs->dumpFile("{$this->testFileDirectory}/file0.txt", 'file0-0');
		$fs->remove("{$this->testFileDirectory}/dir1/dir2/file2.txt");
		$fs->remove("{$this->testFileDirectory}/dir1/dir2");
		$fs->rename("{$this->testFileDirectory}/dir1/file1.txt", "{$this->testFileDirectory}/dir1/dir2");
		$fs->remove("{$this->testFileDirectory}/dir3");
		$fs->mkdir("{$this->testFileDirectory}/dir3");
		$fs->dumpFile("{$this->testFileDirectory}/dir4/file4.txt", 'file4');

		$this->assertTrue($fs->commit());

		// dir1
		$this->assertFileExists("{$this->testFileDirectory}/dir1");
		$this->assertDirectoryExists("{$this->testFileDirectory}/dir1");
		// dir1/dir2
		$this->assertFileExists("{$this->testFileDirectory}/dir1/dir2");
		$this->assertTrue(is_file("{$this->testFileDirectory}/dir1/dir2"));
		$this->assertEquals('file1', file_get_contents("{$this->testFileDirectory}/dir1/dir2"));
		// dir1/dir2/file2.txt
		$this->assertFileNotExists("{$this->testFileDirectory}/dir1/dir2/file2.txt");
		// dir1/file1.txt
		$this->assertFileNotExists("{$this->testFileDirectory}/dir1/file1.txt");
		// file0.txt
		$this->assertFileExists("{$this->testFileDirectory}/file0.txt");
		$this->assertTrue(is_file("{$this->testFileDirectory}/file0.txt"));
		$this->assertEquals('file0-0', file_get_contents("{$this->testFileDirectory}/file0.txt"));
		// dir3
		$this->assertFileExists("{$this->testFileDirectory}/dir3");
		// dir4
		$this->assertFileExists("{$this->testFileDirectory}/dir4");
		$this->assertDirectoryExists("{$this->testFileDirectory}/dir4");
		// dir4/file4.txt
		$this->assertFileExists("{$this->testFileDirectory}/dir4/file4.txt");
		$this->assertTrue(is_file("{$this->testFileDirectory}/dir4/file4.txt"));
		$this->assertEquals('file4', file_get_contents("{$this->testFileDirectory}/dir4/file4.txt"));
	}

	public function testAppendToFile(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$fs->transaction();

		$fs->appendToFile("{$this->testFileDirectory}/file0.txt", 'more content');
		$this->assertSame('file0', file_get_contents("{$this->testFileDirectory}/file0.txt"));
		$this->assertSame('file0more content', $fs->catFile("{$this->testFileDirectory}/file0.txt"));

		$this->assertTrue($fs->commit());
		$this->assertSame('file0more content', file_get_contents("{$this->testFileDirectory}/file0.txt"));
	}

	public function testCopying(): void {
		$fs = new TransactionalFilesystem(__DIR__);
		$this->initFiles($fs);
		$fs->transaction();

		//mirror directory
		$fs->mirror("{$this->testFileDirectory}/dir1/dir5", "{$this->testFileDirectory}/dir1/dir5_copy");
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/dir1/dir5_copy"));
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/dir1/dir5_copy/file5.txt"));
		$this->assertFileNotExists("{$this->testFileDirectory}/dir1/dir5_copy/file5.txt");

		//copy file
		$fs->copy("{$this->testFileDirectory}/file1.txt", "{$this->testFileDirectory}/file1_copy.txt");
		$this->assertTrue($fs->exists("{$this->testFileDirectory}/file1_copy.txt"));
		$this->assertFileNotExists("{$this->testFileDirectory}/file1_copy.txt");

		$this->assertTrue($fs->commit());
		$this->assertFileExists("{$this->testFileDirectory}/dir1/dir5_copy/file5.txt");
		$this->assertFileExists("{$this->testFileDirectory}/file1_copy.txt");
	}

	protected function initFiles(TransactionalFilesystem $fs): void {
		$fs->dumpFile("{$this->testFileDirectory}/file1.txt", 'file1');
		$fs->mkdir("{$this->testFileDirectory}/dir1");
		$fs->dumpFile("{$this->testFileDirectory}/dir1/file2.txt", 'file2');
		$fs->mkdir("{$this->testFileDirectory}/dir1/dir5");
		$fs->dumpFile("{$this->testFileDirectory}/dir1/dir5/file5.txt", 'file5');
		$fs->dumpFile("{$this->testFileDirectory}/dir1/dir5/file6.txt", 'file6');
	}
}
