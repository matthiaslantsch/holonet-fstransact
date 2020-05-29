#Filesystem transaction library

Symfony Filesystem class wrapper using a custom php stream wrapper ([covex-nn/vfs](https://github.com/covex-nn/vfs)) to run filesystem operations
on a virtual simulated filesystem instead based on a transaction (like in a database).
## Why?

Recently I've been running into filesystem errors more and more. I ended up rewriting the hiboard importer multiple
times in desperation trying to make it more robust to the many quirks of different filesystems in different os.
Eventually I would always end up with files in the wrong place, with the wrong name, or database entries not being
correct, forcing me to manually copy the title images of galleries around folders and mass rename files in order to fix it.

That is why I started to explore the idea of having transactions (in the sql sense) with filesystem operations.
## Installation
The preferred / only way to install the library is via composer:

`composer require holonet/fstransact`

or update your `composer.json`

```
    ...
    "require": {
        "holonet/fstransact": "~1.0"
    },
    ...
```

## How to use

#### As a library:
```php
<?php

require 'vendor/autoload.php';

$fs = new \holonet\fstransact\TransactionalFilesystem($baseDir = null);

//use $fs just like you would a normal symfony filesystem object
$fs->exists("/my/ambigious/file");
$fs->rename("/etc/users", "/etc/user");

//start a transaction
$fs->transaction();

//rollback changes in case of errors
//note that just like with sql transactions, if you never call commit()
//on the transaction, the changes are lost either way
//(e.g. when an exception makes your application stop)
$fs->rollback();

//commit the changes to the real filesystem
$fs->commit();

```

## What works:
- inTransaction() checks: The class registers the custom stream wrapper if and only if you start a transaction.
  Otherwise all filesystem operations are done directly on the filesystem.
- You can use the optional $baseDir parameter to specify under which directory to create the
  virtual filesystem (saving in performance if you know all operations will be performed under that directory)
- Transactions are encapsulated with an internal counter, meaning you can keep opening more transactions
  (the changes will be commited on the final commit() call)

## What doesn't work (Pitfalls):
- The vfs always has a base directory path. Since under windows systems not all paths have a common parent
  directory, the library struggles with operations involving multiple different drives
- If you use two instances of this class at once, the custom stream wrapper will throw an error. Use the second optional
  constructor argument to avoid.
  
## Contributor's guide



If you wish to contribute to the library you can just fork it on github and eventually send me a pull request.

In development, I use code quality assurance tools such as php-cs-fixer and psalm. If you contributed a change you can
run ``composer test`` to automatically test your code.
