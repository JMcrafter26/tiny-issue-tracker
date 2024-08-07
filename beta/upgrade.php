<?php
/*
 *      Tiny Issue Tracker v3.2
 *      SQLite based, single file Issue Tracker
 *
 *      Copyright 2010-2013 Jwalanta Shrestha <jwalanta at gmail dot com>
 * 		Copyright 2024 JMcrafter26 <https://github.com/JMcrafter26>
 *      GNU GPL
 */



//  This script is used to upgrade the database from previous versions to the current version
//  Mainly supports SQLite databases, other databases may also work but not tested

$UPDATE_VERSION = 3.3; // The version to which the database will be updated, DO NOT CHANGE THIS VALUE

$DB_CONNECTION = '';
$DB_USERNAME = "";
$DB_PASSWORD = "";
$BACKED_UP = false; // Set to true if you have backed up the database manually
$version = 0;

$prefix = ''; // The prefix of the database tables, if any (e.g. when using multiple instances)
$fileName = 'issue-tracker.php';

if (isset($_GET['file'])) {
    $fileName = $_GET['file'];
}

if (isset($argv[1])) {
    $fileName = $argv[1];
}


header('Content-Type: text/plain');
// check if the file exists
if (!file_exists($fileName)) {
    echo "Error: File not found: $fileName\n";
    exit;
}

// get the database from tiny-issue-tracker.php
if (!$DB_CONNECTION) {
    try {
        $config = file_get_contents($fileName);
        $config = explode("\n", $config);
        $count = 0;
        foreach ($config as $line) {
            if (strpos($line, '$DB_CONNECTION') !== false) {
                $DB_CONNECTION = trim(str_replace(array('$DB_CONNECTION = "', '";'), '', $line));
                $count++;
                // break;
            }
            if (strpos($line, '$DB_USERNAME') !== false) {
                $DB_USERNAME = trim(str_replace(array('$DB_USERNAME = "', '";'), '', $line));
                // break;
                $count++;
            }
            if (strpos($line, '$DB_PASSWORD') !== false) {
                $DB_PASSWORD = trim(str_replace(array('$DB_PASSWORD = "', '";'), '', $line));
                // break;
                $count++;
            }
            if ($count == 3) {
                break;
            }
        }

        // check if $PREFIX is in the file, if so, get the prefix
        if ($prefix == '') {
            $count = 0;
            foreach ($config as $line) {
                if (strpos($line, '$PREFIX') !== false) {
                    $prefix = trim(str_replace(array('$PREFIX = "', '";'), '', $line));
                    $count++;
                    break;
                }
            }
            if ($count == 0) {
                $prefix = '';
            } else if ($count == 1 && $prefix != '') {
                echo "Using prefix: $prefix\n";
            }
        }
    } catch (Exception $e) {
        die('Error: Could not read database file from ' . $fileName . "\n");
    }
} else {
    echo "Please set the database connection details manually in this file\n";
    exit;
}



echo "Upgrading database: $DB_CONNECTION (username: $DB_USERNAME) for file: $fileName\n";

// pdo check and connect
try {
    $db = new PDO($DB_CONNECTION, $DB_USERNAME, $DB_PASSWORD);
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

// get the version from the database
if ($version == 0) {
    try {
        $version = $db->query("SELECT value FROM " . $prefix . "config WHERE key = 'version'")->fetchColumn();
    } catch (PDOException $e) {
        echo "No version found in database, please set the version manually in this file in the variable \$version\n";
        echo "Set it to the version of the database, if you just updated the file, use the previous version\n";
        echo "E.g. if you updated from 3.1 to 3.2, set the version to 3.1\n";
        exit;
    }
}


if ($version >= $UPDATE_VERSION) {
    echo "Database is already up to date with this update script (Update version $UPDATE_VERSION, Database version $version)\n";
    echo "Please make sure you have updated the main script to the latest version\n";
    exit;
} else {
    echo "Updating database from version $version to $UPDATE_VERSION\n";
}

// backup the database
// if is sqlite
if (strpos($DB_CONNECTION, 'sqlite') !== false) {
    $backup = 'backup-tiny-issue-tracker-' . date('Y-m-d-H-i-s') . '.db';
    try {
        copy(str_replace('sqlite:', '', $DB_CONNECTION), $backup);
        $BACKED_UP = true;
    } catch (Exception $e) {
        echo "Error backing up database: " . $e->getMessage();
        exit;
    }
    if (!file_exists($backup)) {
        echo "Error backing up database: Could not create backup\n";
        exit;
    }
    echo "Database backed up to $backup\n";
} else {
    if (!$BACKED_UP) {
        echo "Backup not supported for this database type\n";
        echo "Please backup the database manually before proceeding\n";
        exit;
    }
}

// update the database

// check if collum admin in users exists
$result = $db->query("PRAGMA table_info(users)");
$result->setFetchMode(PDO::FETCH_ASSOC);
$meta = array();
foreach ($result as $row) {
    array_push($meta, $row['name']);
}
$collum_exists = in_array('admin', $meta);

if ($collum_exists) {
    echo "Renaming admin collum to role in users table\n";

    // in users, rename collum admin to role
    try {
        $db->exec("ALTER TABLE users RENAME COLUMN admin TO role");
    } catch (PDOException $e) {
        echo "Error updating users table: " . $e->getMessage();
        exit;
    }

    // if role is 1, set it to 4
    try {
        $db->exec("UPDATE users SET role = 4 WHERE role = 1");
    } catch (PDOException $e) {
        echo "Error updating users table: " . $e->getMessage();
        exit;
    }

    // if user is admin, set role to 5
    try {
        $db->exec("UPDATE users SET role = 5 WHERE role = 4");
    } catch (PDOException $e) {
        echo "Error updating users table: " . $e->getMessage();
        exit;
    }

    // if role is 0, set it to 2
    try {
        $db->exec("UPDATE users SET role = 2 WHERE role = 0");
    } catch (PDOException $e) {
        echo "Error updating users table: " . $e->getMessage();
        exit;
    }

    // if role is 3, set it to 0
    try {
        $db->exec("UPDATE users SET role = 0 WHERE role = 3");
    } catch (PDOException $e) {
        echo "Error updating users table: " . $e->getMessage();
        exit;
    }
} else {
    echo "Skipping adding admin collum to users table\n";
}

unset($meta, $collum_exists, $result);
$result = $db->query("PRAGMA table_info(" . $prefix . "issues)");
$result->setFetchMode(PDO::FETCH_ASSOC);
$meta = array();
foreach ($result as $row) {
    array_push($meta, $row['name']);
}
$collum_exists = in_array('tags', $meta);

if ($collum_exists == false) {
    echo "Adding tags collum to issues table\n";


    // add tags collum to issues
    try {
        $db->exec("ALTER TABLE " . $prefix . "issues ADD COLUMN tags TEXT");
    } catch (PDOException $e) {
        echo "Error updating issues table: " . $e->getMessage();
        exit;
    }
} else {
    echo "Skipping adding tags collum to issues table\n";
}

# check if collum reactions in issues exists
$result = $db->query("PRAGMA table_info(" . $prefix . "issues)");
$result->setFetchMode(PDO::FETCH_ASSOC);
$meta = array();
foreach ($result as $row) {
    array_push($meta, $row['name']);
}
$collum_exists = in_array('reactions', $meta);

if ($collum_exists == false) {
    echo "Adding reactions collum to issues table\n";

    // add reactions collum to issues
    try {
        $db->exec("ALTER TABLE " . $prefix . "issues ADD COLUMN reactions TEXT");
    } catch (PDOException $e) {
        echo "Error updating issues table: " . $e->getMessage();
        exit;
    }
} else {
    echo "Skipping adding reactions collum to issues table\n";
}

$now = date("Y-m-d H:i:s");


// update the version
try {
    $db->exec("CREATE TABLE if not exists " . $prefix . "config (id INTEGER PRIMARY KEY, key TEXT, value TEXT, entrytime DATETIME)");
    if ($db->query("SELECT value FROM " . $prefix . "config WHERE key = 'version'")->fetchColumn()) {
        $db->exec("UPDATE " . $prefix . "config SET value = $UPDATE_VERSION WHERE key = 'version'");
    } else {
        $db->exec("INSERT INTO " . $prefix . "config (key, value, entrytime) VALUES ('version', $UPDATE_VERSION, '$now')");
    }
} catch (PDOException $e) {
    echo "Error updating version: " . $e->getMessage();
    exit;
}

// if table logs exists, add upgraded message
try {
    $db->exec("CREATE TABLE if not exists " . $prefix . "logs (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, details TEXT, priority INTEGER, entrytime DATETIME)");
	$db->exec("INSERT INTO " . $prefix . "logs (user_id, action, details, priority, entrytime) values('0', 'Upgraded', 'Database upgraded to version $UPDATE_VERSION', '4', '$now')");

} catch (PDOException $e) {
    echo "Error updating logs table: " . $e->getMessage();
}

echo "\nDatabase updated successfully\n\n\n";
echo "IF YOU HAVE OTHER INSTANCES MAKE SURE TO UPDATE THEM AS WELL:\n";
echo "1. METHOD: Add the ?prefix=YOUR_DB_PREFIX to the URL\n";
echo "2. METHOD: Run the upgrade.php script with the prefix as the first argument\n";

exit;
