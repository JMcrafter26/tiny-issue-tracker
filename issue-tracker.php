<?php
/*
 *      Tiny Issue Tracker v3.1
 *      SQLite based, single file Issue Tracker
 *
 *      Copyright 2010-2013 Jwalanta Shrestha <jwalanta at gmail dot com>
 * 		Copyright 2024 JMcrafter26 <https://github.com/JMcrafter26>
 *      GNU GPL
 */

///////////////////
// CONFIGURATION //
///////////////////

// no display errors
ini_set('display_errors', 0);
error_reporting(0);


if (!defined("TIT_INCLUSION")) {
	$TITLE = "My Project";              // Project Title
	$EMAIL = "noreply@example.com";     // "From" email address for notifications

	// Array of users.
	// Mandatory fields: username, password (md5 hash)
	// Optional fields: email, admin (true/false)

	$USERS = array(
		array("username" => "admin", "password" => md5("admin"), "email" => "admin@example.com", "admin" => true),
		array("username" => "user", "password" => md5("user"), "email" => "user@example.com"),
	);

	// PDO Connection string ()
	// eg, SQlite: sqlite:<filename> (Warning: if you're upgrading from an earlier version of TIT, you have to use "sqlite2"!)
	//     MySQL: mysql:dbname=<dbname>;host=<hostname>
	$DB_CONNECTION = "sqlite:tit.db";
	$DB_USERNAME = "";
	$DB_PASSWORD = "";

	// Select which notifications to send
	$NOTIFY["ISSUE_CREATE"]     = TRUE;     // issue created
	$NOTIFY["ISSUE_EDIT"]       = TRUE;     // issue edited
	$NOTIFY["ISSUE_DELETE"]     = TRUE;     // issue deleted
	$NOTIFY["ISSUE_STATUS"]     = TRUE;     // issue status change (solved / unsolved)
	$NOTIFY["ISSUE_PRIORITY"]   = TRUE;     // issue status change (solved / unsolved)
	$NOTIFY["COMMENT_CREATE"]   = TRUE;     // comment post

	// Modify this issue types
	$STATUSES = array(0 => "Active", 1 => "Resolved");
}
////////////////////////////////////////////////////////////////////////
////// DO NOT EDIT BEYOND THIS IF YOU DON'T KNOW WHAT YOU'RE DOING /////
////////////////////////////////////////////////////////////////////////

// if (get_magic_quotes_gpc()){ // DEPRECATED

//     if(
foreach ($_GET  as $k => $v) $_GET[$k] = stripslashes($v);
foreach ($_POST as $k => $v) $_POST[$k] = stripslashes($v);
// }

// Here we go...
session_start();

// check for login post
$message = "";
if (isset($_POST["login"])) {
	$n = check_credentials($_POST["u"], md5($_POST["p"]));
	// die(json_encode($n));
	if ($n >= 0) {
		$_SESSION['tit'] = $USERS[$n];

		header("Location: ?");
	} else $message = "Invalid username or password";
}

// check for logout
if (isset($_GET['logout'])) {
	$_SESSION['tit'] = array();  // username
	// destroy session
	session_destroy();
	header("Location: ?");
}

$login_html = "<html>
<head>
	<title>Tiny Issue Tracker</title>
	<meta name='viewport' content='width=device-width, initial-scale=1'>
	<style>
	" . insertCss() . "
	.container {
		display: flex;
		justify-content: center;
		height: 100vh;
	
	} input{font-family:sans-serif;font-size:11px;} label{display:block;}
	h2 {text-align:center;}
	p {text-align:center;}
	</style>
</head>
						 <body>
							<h2>$TITLE - Issue Tracker</h2><p>$message</p>
							<div class='container'>
							<form method='POST' action='" . $_SERVER["REQUEST_URI"] . "'>
						 <label>Username</label><input type='text' name='u' />
						 <label>Password</label><input type='password' name='p' />
						 <label></label><input type='submit' name='login' value='Login' />
						 </form></div></body></html>";

// show login page on bad credential
// if (check_credentials() == -1) die($login_html);
if (!isset($_SESSION['tit']['username']) || !isset($_SESSION['tit']['password']) || check_credentials($_SESSION['tit']['username'], $_SESSION['tit']['password']) == -1) die($login_html);

// Check if db exists
try {
	$db = new PDO($DB_CONNECTION, $DB_USERNAME, $DB_PASSWORD);
} catch (PDOException $e) {
	die("DB Connection failed: " . $e->getMessage());
}

// create tables if not exist
$db->exec("CREATE TABLE if not exists issues (id INTEGER PRIMARY KEY, title TEXT, description TEXT, user TEXT, status INTEGER NOT NULL DEFAULT '0', priority INTEGER, notify_emails TEXT, entrytime DATETIME)");
$db->exec("CREATE TABLE if not exists comments (id INTEGER PRIMARY KEY, issue_id INTEGER, user TEXT, description TEXT, entrytime DATETIME)");

$issue = [];
$comments = [];
$status = 0;


if (isset($_GET["id"])) {
	// show issue #id
	$id = pdo_escape_string($_GET['id']);
	$issue = $db->query("SELECT id, title, description, user, status, priority, notify_emails, entrytime FROM issues WHERE id='$id'")->fetchAll();
	$comments = $db->query("SELECT id, user, description, entrytime FROM comments WHERE issue_id='$id' ORDER BY entrytime ASC")->fetchAll();

	// add user email to comments
	foreach ($comments as $i => $comment) {
		// add gravatar hash
		$comments[$i]['gravatar'] = md5(strtolower(trim($USERS[array_search($comment['user'], array_column($USERS, 'username'))]['email'])));
	}
}

// if no issue found, go to list mode
if (!isset($issue) || count($issue) == 0) {


	// show all issues

	$status = 0;
	if (isset($_GET["status"]))
		$status = (int)$_GET["status"];

	$issues = $db->query(
		"SELECT id, title, description, user, status, priority, notify_emails, entrytime, comment_user, comment_time " .
			" FROM issues " .
			" LEFT JOIN (SELECT max(entrytime) as max_comment_time, issue_id FROM comments GROUP BY issue_id) AS cmax ON cmax.issue_id = issues.id" .
			" LEFT JOIN (SELECT user AS comment_user, entrytime AS comment_time, issue_id FROM comments ORDER BY issue_id DESC, entrytime DESC) AS c ON c.issue_id = issues.id AND cmax.max_comment_time = c.comment_time" .
			" WHERE status=" . pdo_escape_string($status ? $status : "0 or status is null") . // <- this is for legacy purposes only
			" ORDER BY priority, entrytime DESC"
	)->fetchAll();

	// get the comment count for each issue
	foreach ($issues as $i => $issue) {
		$issues[$i]['comment_count'] = $db->query("SELECT count(*) FROM comments WHERE issue_id='{$issue['id']}'")->fetchColumn();
	}
	unset($i, $issue, $comments);

	// $issue = [];

	$mode = "list";
} else {
	$issue = $issue[0];
	$mode = "issue";
}

//
// PROCESS ACTIONS
//

// Create / Edit issue
if (isset($_POST["createissue"])) {

	$id = pdo_escape_string($_POST['id']);
	$title = pdo_escape_string($_POST['title']);
	$description = pdo_escape_string($_POST['description']);
	$priority = pdo_escape_string($_POST['priority']);
	$user = pdo_escape_string($_SESSION['tit']['username']);
	$now = date("Y-m-d H:i:s");

	// gather all emails
	$emails = array();
	for ($i = 0; $i < count($USERS); $i++) {
		if ($USERS[$i]["email"] != '') $emails[] = $USERS[$i]["email"];
	}
	$notify_emails = implode(",", $emails);

	if ($id == '')
		$query = "INSERT INTO issues (title, description, user, priority, notify_emails, entrytime) values('$title','$description','$user','$priority','$notify_emails','$now')"; // create
	else
		$query = "UPDATE issues SET title='$title', description='$description' WHERE id='$id'"; // edit

	if (trim($title) != '') {     // title cant be blank
		@$db->exec($query);
		if ($id == '') {
			// created
			$id = $db->lastInsertId();
			if ($NOTIFY["ISSUE_CREATE"])
				notify(
					$id,
					"[$TITLE] New Issue Created",
					"New Issue Created by {$user}\r\nTitle: $title\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id"
				);
		} else {
			// edited
			if ($NOTIFY["ISSUE_EDIT"])
				notify(
					$id,
					"[$TITLE] Issue Edited",
					"Issue edited by {$user}\r\nTitle: $title\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id"
				);
		}
	}

	header("Location: ?id=$id");
}

// Delete issue
if (isset($_GET["deleteissue"])) {
	$id = pdo_escape_string($_GET['id']);
	$title = get_col($id, "issues", "title");

	// only the issue creator or admin can delete issue
	if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username'] == get_col($id, "issues", "user")) {
		@$db->exec("DELETE FROM issues WHERE id='$id'");
		@$db->exec("DELETE FROM comments WHERE issue_id='$id'");

		if ($NOTIFY["ISSUE_DELETE"])
			notify(
				$id,
				"[$TITLE] Issue Deleted",
				"Issue deleted by {$_SESSION['tit']['username']}\r\nTitle: $title"
			);
	}
	header("Location: {$_SERVER['PHP_SELF']}");
}

// Change Priority
if (isset($_GET["changepriority"])) {
	$id = pdo_escape_string($_GET['id']);
	$priority = pdo_escape_string($_GET['priority']);
	if ($priority >= 1 && $priority <= 3) @$db->exec("UPDATE issues SET priority='$priority' WHERE id='$id'");

	if ($NOTIFY["ISSUE_PRIORITY"])
		notify(
			$id,
			"[$TITLE] Issue Priority Changed",
			"Issue Priority changed by {$_SESSION['tit']['username']}\r\nTitle: " . get_col($id, "issues", "title") . "\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id"
		);

	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// change status
if (isset($_GET["changestatus"])) {
	$id = pdo_escape_string($_GET['id']);
	$status = pdo_escape_string($_GET['status']);
	@$db->exec("UPDATE issues SET status='$status' WHERE id='$id'");

	if ($NOTIFY["ISSUE_STATUS"])
		notify(
			$id,
			"[$TITLE] Issue Marked as " . $STATUSES[$status],
			"Issue marked as {$STATUSES[$status]} by {$_SESSION['u']}\r\nTitle: " . get_col($id, "issues", "title") . "\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$id"
		);

	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Unwatch
if (isset($_POST["unwatch"])) {
	$id = pdo_escape_string($_POST['id']);
	setWatch($id, false);       // remove from watch list
	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

// Watch
if (isset($_POST["watch"])) {
	$id = pdo_escape_string($_POST['id']);
	setWatch($id, true);         // add to watch list
	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}


// Create Comment
if (isset($_POST["createcomment"])) {

	$issue_id = pdo_escape_string($_POST['issue_id']);
	$description = pdo_escape_string($_POST['description']);
	$user = $_SESSION['tit']['username'];
	$now = date("Y-m-d H:i:s");

	if (trim($description) != '') {
		$query = "INSERT INTO comments (issue_id, description, user, entrytime) values('$issue_id','$description','$user','$now')"; // create
		$db->exec($query);
	}

	if ($NOTIFY["COMMENT_CREATE"])
		notify(
			$id,
			"[$TITLE] New Comment Posted",
			"New comment posted by {$user}\r\nTitle: " . get_col($id, "issues", "title") . "\r\nURL: http://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}?id=$issue_id"
		);

	header("Location: {$_SERVER['PHP_SELF']}?id=$issue_id");
}

// Delete Comment
if (isset($_GET["deletecomment"])) {
	$id = pdo_escape_string($_GET['id']);
	$cid = pdo_escape_string($_GET['cid']);

	// only comment poster or admin can delete comment
	if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username'] == get_col($cid, "comments", "user"))
		$db->exec("DELETE FROM comments WHERE id='$cid'");

	header("Location: {$_SERVER['PHP_SELF']}?id=$id");
}

//
//      FUNCTIONS
//

// PDO quote, but without enclosing single-quote
function pdo_escape_string($str)
{
	global $db;
	$quoted = $db->quote($str);
	return ($db->quote("") == "''") ? substr($quoted, 1, strlen($quoted) - 2) : $quoted;
}

// check credentials, returns -1 if not okay
function check_credentials($u = false, $p = false)
{
	global $USERS;


	// if($u == false && $p == false) {
	// 	if(isset($_SESSION['tit']['username'])) {
	// 		$u = $_SESSION['tit']['username'];
	// 	} else {
	// 		$u = '';
	// 	}

	// 	if(isset($_SESSION['tit']['password'])) {
	// 		$p = $_SESSION['tit']['password'];
	// 	} else {
	// 		$p = '';
	// 	}
	// }

	// echo $u . ' | ' . $p;


	$n = 0;
	foreach ($USERS as $user) {
		if (strcasecmp($user['username'], $u) === 0 && $user['password'] == $p) return $n;
		$n++;
	}
	// die(json_encode($USERS));
	return -1;
}

// get column from some table with $id
function get_col($id, $table, $col)
{
	global $db;
	$result = $db->query("SELECT $col FROM $table WHERE id='$id'")->fetchAll();
	return $result[0][$col];
}

// notify via email
function notify($id, $subject, $body)
{
	global $db;
	$result = $db->query("SELECT notify_emails FROM issues WHERE id='$id'")->fetchAll();
	$to = $result[0]['notify_emails'];

	if ($to != '') {
		global $EMAIL;
		$headers = "From: $EMAIL" . "\r\n" . 'X-Mailer: PHP/' . phpversion();

		try {
			mail($to, $subject, $body, $headers);       // standard php mail, hope it passes spam filter :)
		} catch(Error) {
			
		}
	}
}

// start/stop watching an issue
function watchFilterCallback($email)
{
	return $email != $_SESSION['tit']['email'];
}

function setWatch($id, $addToWatch)
{
	global $db;
	if ($_SESSION['tit']['email'] == '') return;

	$result = $db->query("SELECT notify_emails FROM issues WHERE id='$id'")->fetchAll();
	$notify_emails = $result[0]['notify_emails'];

	$emails = $notify_emails ? explode(",", $notify_emails) : array();

	if ($addToWatch) $emails[] = $_SESSION['tit']['email'];
	else $emails = array_filter($emails, "watchFilterCallback");
	$emails = array_unique($emails);

	$notify_emails = implode(",", $emails);

	$db->exec("UPDATE issues SET notify_emails='$notify_emails' WHERE id='$id'");
}

function timeToString($time)
{
	$currentTime = time();
	$diff = $currentTime - strtotime($time);

	if ($diff < 60) {
		$timeAgo = $diff . " second" . ($diff > 1 ? "s" : "");
	} elseif ($diff < 3600) {
		$diff = floor($diff / 60);
		$timeAgo = $diff . " minute" . ($diff > 1 ? "s" : "");
	} elseif ($diff < 86400) {
		$diff = floor($diff / 3600);
		$timeAgo = $diff . " hour" . ($diff > 1 ? "s" : "");
	} elseif ($diff < 604800) {
		$diff = floor($diff / 86400);
		$timeAgo = $diff . " day" . ($diff > 1 ? "s" : "");
	} elseif ($diff < 2592000) {
		$diff = floor($diff / 604800);
		$timeAgo = $diff . " week" . ($diff > 1 ? "s" : "");
	} elseif ($diff < 31536000) {
		$diff = floor($diff / 2592000);
		$timeAgo = $diff . " month" . ($diff > 1 ? "s" : "");
	} else {
		$diff = floor($diff / 31536000);
		$months = $diff % 12;
		$years = floor($diff / 12);
		$timeAgo = $years . " year" . ($years > 1 ? "s" : "") . ($months > 0 ? " " . $months . " month" . ($months > 1 ? "s" : "") : "");
	}
	return $timeAgo;
}

function getUrl()
{
	return (empty($_SERVER['HTTPS']) ? 'http' : 'https') . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
}

function insertCss()
{
	// gzip decode
	return gzdecode(base64_decode('H4sIAAAAAAAAC+0d2Y7juPFXhB0M3EYkj+Sr2zJ6NslkFwkwuwGy2IdkMgEoibKU1uGV6D7W0E8k78ljXvJx+YQUD92STR/T27vjEaZb4lFVLFaRrGKRbSZxTLaaZiH7bpXEm8jRrNh5Ml+5rrusJpuvsEufWqKGAgJFr+kDGSkOsE38ODJfLTB9II3gR6KFyIe0yZw+eZqV+CsPauu6XhTbEAyIrvXr62uKKPCju5SWuJ47Bny7sb1h34u55SKLkhInDk7MV45FH0iwYwfnMFHkh4iSozmbhL2YIyOltTaEQKqFUijr6LZru2WqF99TiAuLPrRRdhIHgYUSjXib0DKTlXVlXOuq+D9sFxEg7lFyVYdKy7pxEmrrANnYiwNG+2JKnzyLMsJ8ZTj0gTQA4iMrADonCzSZ2JDkAd8CzjvX1Qu2ayhJ4gdzkwRXXziIIBMav8Jv0vvVrx7DYGl7KEkxud0QV7tRX0/efQmpChCVAl9uB8ZIHyg4Avb50ep2wEoNvnw9+UqBogCjWtIYKFA3Sm8HHiFr882bh4eH0cNkFCerN2NgPcUpipiPtA+7ChqLxeINyx0oHqbNuR3Mx6PZQHnwHeIBHmM+WgwU1w+C28Hr8cSYG+74eiBIWiPiKc7t4BvDmI0mqjGaK+8MYzK6VnXFMIBG+ltfjGYs6/3sBt5m49G18v56NOXFZ6MbKDQZjWnR0Zwn6iokKLoKmSwRSkPtGdSeG6MJVJoDNqDTUGbX/G2mAPApf3s3W+Svc53WoQVpRV77PSV2zGC+Y83jaNgbRVu05S+DN6KdlJfw+sUw+3WIHR8pV+sE1DBJQdKDOAHR83CITQcld8Ot2afMY31sTW4a+swZ2qHPBhpPx25dnw37em7P6vpcKF1Nn/nAUdVntLAMC5X6PDWQwwr16/NsPF/c6IU+u66Fb2bSKq3bxsywWyqtT3Wkux0q3a2p3VpN1V9X4elWZrSgT12ZOUdKTXYWUwOP65qMXceaTj4bZeZzyeerzJlHwmBbShhT5tbUojDRaZbi0tqQzqEihLiu+sNKbdYVJvH8SGYs6SRQqJCSDygnkZZdyLhw46XLBv21deOIaC4K/eDJTJ9SgkNt46saWq8DrPEE9bd09PsG2d+xz6+hhjr4Dq9irHz/h4E6+FNsxSSGlz8+Pq1wBC/fW5uIbODlHYoISnAQwPvXfoKU71CUwvvvkth38o/f4+AeE99Gyrd4gwclbOWrMP67Dwm/odQo72gji7RvAWc9KQVwMMckvrsEgrHGR2vTGE2XIXoUY8SNrq8f4TtZwTQ/hncFbUi8XCOHTigmHYwh/wHmau0hQWvTSjC60+j3UnSCWOpXWV4sHIbL6iqEjnCV755RjFVOcARzLKUgXhM/9H/E7/HKt/zAJ08y8sL6sopbiMhe9Jk0dNF8sTLqbj4IFVtpbEkCneGzRUwFI6ukwKpGoR2EEpWvidrpHQUftdRDTvxQTWVY6GqEJWJYIC13IebUttdZw05y9paWBlqQvrds2aD+orSZUp126Ynn6InMj9YbchH4n17gLx3xLPJOR3uY09FF5H96kb/0xTPNt5ln8KVyCkszczwa41AsIjUSr00d8lVvrHoT1Zuq3kz15luRDWtjEoemMS6WnazGeLp+pEDzlT14dVtLKu52knKPlZDoorMXUuaNz4ZxLIlxcjaME0mM07NhnEpinJ0N40wS4/xsGOdyGFOSxNHqXFjr0Pa0talaqqXy+irxuFY+cFNvruvZD6ZpYVBpDMAjgiNiRnGEaTJyCU7qqVYQ23c/bGKCt2LtH2CXmKCbShoHYKKWDtzufE4vc/YOc6vSGM1wqOiFTck+DRgw+ABCnsBR6hMU+LbUMv6cJGbZDy+2oeegDOzPgl1vXdgxgA6vEBPBSI8CAQcG7bKwYvvA4FbRDFqW4DTtyvngQVv+djsAwzcg8V/NwceW5A3+9+9//kcZlGUJDnoL/qtaMA3TvoL/+C8UhB64q9j7uYOIbpq1UjmDCof8MOdjghwf9ijoxFS6P+BLYT+XpabL9F4PQcL3v5emLENv6X6Iit7WhgY/8sAtQoRbQeU7CCpb6X8gT2sMnNpYoU8GH2uJ0Ge4mcZBNBKhCfYdrBia9VG0wq00x48HH7f2JkmBsnXsQ5ck3PwThG0dP4UtkyeTCVbWxtEAlRf3I/BYgExxC0awTuyTVvlVbLxU/Uwlq9kedg+ry+ThsurzEwxeloubPEUsVvjW17xcvYgFzbwiNsxnJrSKjqsNCaNF4w2hay4+7Eobc+1Wip29/a2UcmzVGN6chUpuF26tS9eUjqVP3Td1lu/qnMIKunRP1Sb81B3UZPuuLhLj46WD6NqX8+JTd0+d5RKDW5sgET/UR1AZlzDMpwIxXUzohJAnsfUaTTlA69u0iMAHCVqES7axPngZzZOk7NjGinXPy2trP2HHNjVfzr28tu6g7JDG8i+ThebUtvZE+F57a68a5COvax0YRDTRHgydavYp6ZXEdwT1Qjyfi/h+dEfQnovbcxG/A98R1DNkoCshWBZiw36cgAshNy1uqCXaYXHts9+4gZWHAABAvv0Pb1lXwbrBYuj6a2GMAcU0NCGA8EF/FZngus0CZOGgmRH6jhPgho0EToplaeOxoARuGTLYYAuRq1Y7htVkQd6w0zgVxrAwjYvVGDjDrDsfwuzWa3Bxo8gWRldRoGak1s088B5Q77n/I2W94AmkLEFgqWmYt7mAxWmFPkw/DreCw5R39XwI96P5lS6e0oE27xz6kS8Q20HZyi82UPAzj/pVQJDsKyouiqbQ7ZGhMoP3N+xdiWKIiQEBJrtCWPLYpmpgqTTcxtQ/k5voO+SU9+MvV04/84DWn1hOD7H1LlJ5kcqLVF7GystYeRkrLzP4y5nBeS3T1MJUw49rFDmFEcZMM57/IdwExF8H+OO2vjbl22ulz4itL/lGG7Wo3SB+0J5MGscuDEu2Gb4tA6FgX5c+lKrqbnpHdm0zXdYxcC58OZ+ej/zzIsxdds9H/3kRlmb787Xg3Ch7vEMmsol/j7ucRF1ZhXexq5pw3nVkFa6xTmzMa5NniZ7jXzyUlm7JmOwtQAT/+YoOKEKhYbSg5ysd4fMpvwWY4rtgZ56ShyuAIwY8VTBSYGcZrxGEuzxBvE5Gx6T4x+o5z3zDSBzdbu0ZVYoOMzaiMRKPB3EWGMfWlDl53MsicSD2FBbJgTgLjGNrZq6PAwdkXjhJTaOqkjwIa1nJqqpjLbcaDiIiyPRlR5RsNapEpoNOpi/LArzCMCmXQTCjRR7GVgkt7FBnEeXLaK1EwonPyoKC6fUaVDMiXXDE8FeLkukqZube3RTopizdRBHVcw3g23cVF2zuXQVHDLA0r1U5HTUap9XDUvSz40aMXUuiRrdOjtgVlGtVx8n+XXQ1tnZ6cLDDkfX5hkqHEB4dXFM84ZXu0CfnJz0yKPYS2GudB7D8q3FRHJZrU8tqDZc9vvpqoLh2fT6+ijZXCBSXEfQR2M1KfrfBAZ0mIUzHNvFgWk4QIDoNsK992kYLvlhVa7bi/MxpalauV8+qW2dh0Hn0hU3fu0Wme6bIOcAn6I4MAW7OZptWqX5iqG9fo4vB+v6tvCSCEFcCtA36tGV0eYAMnNBdvc05RZoF0A0MzL8gHrWbcxqPDlf1ykKsCO/XX9oAkJ57pjxJ5U6dGj+BgjRBH6Um52rXkUKNmO9Bc7Adi+uW2NKrOPbALmarmkbsiicpqouQWHEdVAeUDIn4mSYRQBxOqBGQ0fMQBw89PeezKKwi2s2EbVLQPzbvtCyz0gaisTISjWVkFlGm7DKrFmapTcxWe6W6MUXh+mfAJUbmObjUaq8Ul4gf/hxkiZF5Di612ivFJQDITiHl/nfuea/HSxUe90fucQcwOcXiGsMqxfnNaOLQTe0YWvUYThhHMazabKmzMRWU4r61TpRZdmc5x65dKhN0Ps82M+qmbPtQmdR9OYUYAVh6pk/8lmEDbZ3cpTSSwNpNzyf9nU2Xh3/w1Buu1HswaONteYFR1XZgAujB3Nc+ekVdB3192Mg8eNVUYGxiqbFrJ5aMUBHNwQCOAK1TbOYvTbdguS5kzWeVNdBJ8JeZrv+IHQ4PNuTWdBLlkyoPzKRh3BkBz7hXaDUdmhollu04z85LoDLiYZRLSkFeH59b+Qezeg+6OsP3oMsIPRG7fQYB2YXoEBmh904pBASbeJrt+YFzhe9xNNyecPhRinx5vAcc2emDqvQeeRGXD+8FTq82Palh/SSI+1IlSdjXxlbIeHluWQYHv7Ds9Hb2ktG6NG03JVnF75hfuleEnFeHLJjPOooe5yhtL7MkN7Ek0EtNSp2gmuZy6ybpFszmNYVnbFfLdu87l9CkQWomPzfKXngHHOjovM9XdndTFnvlemBZAl4ABcKhW9z33KH24kL3HrUvavYaRLXrSkzz2VDJb16XJFUGXKkmyJQ/ipCOuYbfw72XN3tYLA0mczBBfpAWsVhugMFmhR+a4yfidnCouwmjJVsVaj7BYcqKgSWHkmrE2FFzdn2/WGwa5zvK7GqVjosUhP1per7jYKn7jvN2njy9C0Af4jWOiiA1Pr+JrLdmgFLC59zGDVh6vbqSbkIo8NS8J4sdBhJZeccEPsCkzD/p2ouaUS+4rDGm859646DZwXdW5GSftEwUQPjgp+ZfPDyg31dYsJ8euBKV2KUs5Q1lOahiuBV1wEhO7sqYkJ3m+gFc2Idnj8kOWIJ4deqiWPLO3p03l9T24Bp2Ug21cITUdFr2/HDe3BquuqnUgSs7APTZPCW9PXPoYMLgvKUGLjDf9ZNi3DhFyVv9p7DN0XJcFRpPO4bf9VwbFipuARuz06bSHJFryQEjAQdb6hHNdJK47v2FBQL8W9jLZim6NwL0g9dyk1yBR40vmTpzCkyfDoO4i+tE30NxZo+Vof0luCr+rE5bqulf6ZAatQSBAp74sx598A4AeLIThJqa+VVmlfGcHZoVZKwTmK/YJd0qEKSye7TEKKyKwTc/Jcxvq9pxRQws9LKesl07txnD2r6hr6CjE5bK21MQ2bpbsJys6pNGLWv3/EKLHuDIccf0gU3BorrLB4PO6Tb7P0g0Z11cagAA'));
}

function insertJquery()
{
	// gzip decode
	return gzdecode(base64_decode('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'));
}

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">

<head>
	<title><?php echo $TITLE, isset($_GET["id"]) ? (" - #" . $_GET["id"]) : "", " - Issue Tracker"; ?></title>
	<meta http-equiv="content-type" content="text/html;charset=utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1">

	<!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/water.css@2/out/water.css"> -->
	<style>
		<?php echo insertCss(); ?>
		/* html { overflow-y: scroll;}
		body { font-family: sans-serif; font-size: 11px; background-color: #aaa;}
		a, a:visited{color:#004989; text-decoration:none;}
		a:hover{color: #666; text-decoration: underline;}
		label{ display: block; font-weight: bold;}
		table{border-collapse: collapse;}
		th{text-align: left; }
		tr:hover{background-color: #f0f0f0;} */

		h1 a {
			color: var(--text-main);
			text-decoration: none;
		}

		#menu {
			float: right;
		}

		#container {
			/* width: 80vw; */
			width: 90%;
			margin: 0 auto;
			padding: 20px;
		}

		#footer {
			padding: 10px 0 0 0;
			text-align: center;
			margin-top: 20px;
		}

		#create {
			padding: 15px;
		}

		.issue {
			padding: 10px 20px;
			margin: 10px 0;
		}

		.comment {
			padding: 5px 10px 10px 10px;
			margin: 10px 0;
			border: 1px solid var(--border);
			border-radius: 6px;
		}

		/* .comment:target{outline: 2px solid #444;} */
		.comment-meta {
			color: #666;
		}

		.p1,
		.p1 a {
			color: red;
		}

		.p3,
		.p3 a {
			color: #666;
		}

		.hide {
			display: none;
		}

		.left {
			float: left;
		}

		.right {
			float: right;
		}

		.clear {
			clear: both;
		}

		table {
			border-collapse: collapse;
			/* round corners */
			-moz-border-radius: 6px;
			-webkit-border-radius: 6px;
			border-radius: 6px;
			border: 1px solid #ccc;
		}

		.border {
			border: 1px solid #ccc;
			padding: 10px;
			margin: 10px 0;
			border-radius: 6px;
		}

		.between {
			display: flex;
			justify-content: space-between;
			/* warp to next line if needed */
			flex-wrap: wrap;
		}

		.hiName {
			margin-bottom: 0px;
			opacity: 0.7;
		}

		.projectName {
			margin-top: 0px;
		}

		svg {
			width: 1em;
			height: 1em;
			vertical-align: middle;
		}

		.issueList {
			display: flex;
			flex-direction: column;
			width: 100%;
			padding: 0;
			margin: 0;
			border-radius: 6px;
			border: 1px solid var(--border);
		}

		.issueList a {
			text-decoration: none;
			color: var(--text-main);
		}

		.issueItem {
			display: flex;
			flex-direction: row;
			padding: 1em;
			border-bottom: 1px solid var(--border);
			border-radius: 6px 6px 0 0;
		}

		.issueItem:hover {
			/* add a small effect on hover that darkens the background */
			background-color: rgba(0, 0, 0, 0.05);
		}

		.issueItem .issueStatus {
			margin-right: 1em;
		}

		.issueItem .itemBody {
			display: flex;
			flex-direction: column;
			width: 100%;
		}

		.issueItem .itemBody .issueTitle {
			font-size: 1.2em;
			font-weight: bold;
		}

		.issueItem .itemBody .itemDetails {
			display: flex;
			flex-direction: row;
			font-size: 0.8em;
			color: var(--form-placeholder);
		}

		.issueItem .itemBody .itemDetails .right {
			margin-left: auto;
		}

		.issueItem .itemBody .watchStatus {
			float: right;
		}

		.active {
			color: #3fb950;
		}

		.closed {
			color: #9871dd;
		}

		.important {
			color: #f85149 !important;
		}

		.unimportant {
			opacity: 0.5;
		}

		.ctxmenu {
			position: fixed;
			background: var(--background-alt);
			color: var(--text-main);
			cursor: pointer;
			border: 8px solid transparent;
			padding: -5rem;
			/* invisible border to enchance user experience */
			border-radius: 6px;
		}

		.ctxmenu>a {
			/* make padding the same with as every element (50px) */
			display: inline-block;
			/* padding left and right to 1rem */
			padding: 0 1rem;
			margin: 0;
			text-decoration: none;
		}

		.ctxmenu>a:hover {
			background: var(--button-hover);
			border-radius: 5px;
		}

		.btn {
			background: var(--button-base);
			color: var(--text-main);
			border: none;
			border-radius: 6px;
			padding: 0.6rem 2rem;
			cursor: pointer;
			text-decoration: none;
		}

		.btn:hover {
			background: var(--button-hover);
			text-decoration: none;

		}

		.issueContainer {
			/* display: flex; */
			/* flex-direction: column; */
			/* width: 100%; */
			border-radius: 6px;
			border: 1px solid var(--border) !important;
			padding: 1em;
			padding-top: 0;
			margin-top: 10px;
		}


		.issueContainer h2 {
			margin-top: 0;
		}

		.gravatar {
			border-radius: 50%;
			margin-right: 3px;
			vertical-align: middle;
		}

		.userCirlce {
			border-radius: 50%;
			border: 1px solid #666;
			width: 1em;
			height: 1em;
			/* background: #ccc; */
			display: inline-block;
			margin-right: 3px;
			vertical-align: middle;
		}

		.no-text-decoration {
			text-decoration: none !important;
			cursor: pointer;
		}
	</style>

	<script>
		<?php echo insertJquery(); ?>
	</script>
	<script>
		! function(e, t, a) {
			"use strict";

			function n(t, a) {
				e.addEventListener(t, function(e) {
					e.defaultPrevented || a(e)
				}, !1)
			}

			function r(t, a) {
				e.addEventListener(t, a, !0)
			}

			function i(t, a, n) {
				var r = e.createEvent("CustomEvent");
				return r.initCustomEvent("ajaxify:" + a, !0, !0, n || null), t.dispatchEvent(r)
			}

			function o(t, a) {
				var n = e.body;
				i(n, "update", a) && n.parentNode.replaceChild(t.body, n), l.indexOf(c) < 0 && l.push(c), e.title = t.title
			}

			function u(t) {
				var a = d.exec(t),
					n = e.implementation.createHTMLDocument(a && a[1] || "");
				return n.body.innerHTML = t.trim().replace(a && a[0], ""), n
			}
			if ("function" == typeof a.pushState) {
				var s, f = function(e) {
						return e
					},
					d = /<title>(.*?)<\/title>/,
					l = [],
					c = {};
				n("click", function(a) {
					for (var n = e.body, r = a.target; r && r !== n; r = r.parentNode)
						if ("a" === r.nodeName.toLowerCase()) {
							if (!r.target) {
								var o = r.href;
								if ("true" === r.getAttribute("aria-disabled")) a.preventDefault();
								else if (o && 0 === o.indexOf("http")) {
									var u = t.href;
									o === u || o.split("#")[0] !== u.split("#")[0] ? i(r, "fetch") && a.preventDefault() : (t.hash = r.hash, a.preventDefault())
								}
							}
							break
						}
				}), n("submit", function(e) {
					var t = e.target;
					if (!t.target)
						if ("true" === t.getAttribute("aria-disabled")) e.preventDefault();
						else {
							var a, n = t.getAttribute("enctype");
							if ("multipart/form-data" === n) a = new FormData(t);
							else {
								a = {};
								for (var r, o = 0; r = t.elements[o]; ++o) {
									var u = r.type;
									if (u && r.name && !r.disabled) {
										var d = r.name;
										if ("select-multiple" === u)
											for (var l, c = 0; l = r.options[c]; ++c) l.selected && (a[d] = a[d] || []).push(l.value);
										else("checkbox" !== u && "radio" !== u || r.checked) && (a[d] = r.value)
									}
								}
							}
							if (i(t, "serialize", a)) {
								if (a instanceof FormData) s = a;
								else {
									var p = "text/plain" === n ? f : encodeURIComponent,
										v = p === f ? / /g : /%20/g;
									s = Object.keys(a).map(function(e) {
										var t = p(e),
											n = a[e];
										return Array.isArray(n) && (n = n.map(p).join("&" + t + "=")), t + "=" + p(n)
									}).join("&").replace(v, "+")
								}
								i(t, "fetch") && e.preventDefault(), s = null
							} else e.preventDefault()
						}
				}), n("ajaxify:fetch", function(e) {
					var t = e.target,
						a = new XMLHttpRequest,
						n = (t.method || "GET").toUpperCase(),
						r = t.nodeName.toLowerCase(),
						o = t.nodeType,
						u = e.detail;
					"a" === r ? u = u || t.href : "form" === r && (u = u || t.action, "GET" === n && s && (u += (~u.indexOf("?") ? "&" : "?") + s, s = null)), ["abort", "error", "load", "timeout"].forEach(function(e) {
						a["on" + e] = function() {
							1 === o && t.removeAttribute("aria-disabled"), i(t, e, a)
						}
					}), a.open(n, u, !0), i(t, "send", a) && (a.setRequestHeader("X-Requested-With", "XMLHttpRequest"), "GET" !== n && a.setRequestHeader("Content-Type", t.getAttribute("enctype") || t.enctype), a.send(s), 1 === o && t.setAttribute("aria-disabled", "true"))
				}), r("ajaxify:load", function(e) {
					var a = e.detail,
						n = a.response,
						r = a.responseURL;
					!r && n && n.URL && (r = a.getResponseHeader("Location"), r && (r = t.origin + r, Object.defineProperty(a, "responseURL", {
						get: function() {
							return r
						}
					})))
				}), n("ajaxify:load", function(e) {
					var n = e.detail,
						r = u(n.responseText),
						i = {
							body: r.body
						};
					r.title ? i.title = r.title : i.title = n.status + " " + n.statusText, o(i, r), c = {};
					var s = n.responseURL;
					s !== t.href && a.pushState(l.length, i.title, s)
				}), r("ajaxify:update", function(t) {
					var a = t.detail;
					"string" == typeof a && (a = u(a), Object.defineProperty(t, "detail", {
						get: function() {
							return a
						}
					})), c.body = t.target, c.title = e.title
				}), window.addEventListener("popstate", function(e) {
					if (!e.defaultPrevented && e.state >= 0) {
						var t = l[e.state];
						t && (o(t, t.body), c = t)
					}
				}), a.replaceState(0, e.title)
			}
		}(window.document, window.location, window.history);
	</script>
</head>

<body>
	<div id='container'>
		<div id="menu">
			<?php
			foreach ($STATUSES as $code => $name) {
				$style = (isset($_GET['status']) && $_GET['status'] == $code) || (isset($issue) && $issue['status'] == $code) ? "style='font-weight:bold;'" : "";
				echo "<a href='{$_SERVER['PHP_SELF']}?status={$code}' alt='{$name} Issues' $style>{$name} Issues</a> | ";
			}
			?>
			<a href="<?php echo $_SERVER['PHP_SELF']; ?>?logout" alt="Logout">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-log-out">
					<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
					<polyline points="16 17 21 12 16 7"></polyline>
					<line x1="21" y1="12" x2="9" y2="12"></line>
				</svg>
				Logout</a>
		</div>

		<h3 class="hiName">Hi, <?php echo $_SESSION['tit']['username']; ?>!</h3>
		<h1 class="projectName"><a href="?"><?php echo $TITLE; ?></a></h1>

		<button onclick="document.getElementById('create').className='';document.getElementById('create').showModal();document.getElementById('title').focus();">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit">
				<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
				<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
			</svg>
			<?php
			//  echo ($issue['id']==''?"Create":"Edit"); 
			if (!isset($issue['id']) || $issue['id'] == '') {
				echo 'New';
			} else {
				echo 'Edit';
			} ?>
		</button>
		<dialog id="create" class='<?php echo isset($_GET['editissue']) ? '' : 'hide'; ?>'>
			<form method="POST" action='<?php echo getUrl(); ?>'>
				<input type="hidden" name="id" value="<?php echo (isset($issue) ? $issue['id'] : ''); ?>" />
				<label>Title</label><input type="text" size="50" name="title" id="title" value="<?php echo htmlentities((isset($issue['title']) ? $issue['title'] : '')); ?>" />
				<label>Description</label><textarea name="description" rows="5" cols="50"><?php echo htmlentities((isset($issue['description']) ? $issue['description'] : '')); ?></textarea>

				Priority
				<select name="priority">
					<option value="1">High</option>
					<option selected value="2">Medium</option>
					<option value="3">Low</option>
				</select>
				<label></label><input style="float: right;" type="submit" name="createissue" value="<?php echo (empty($issue['id']) ? "Create" : "Edit"); ?>" />
				<? if (!$issue['id']) { ?>
				<? } ?>

				<button onclick="document.getElementById('create').close();document.getElementById('create').className='hide';" style="float: right;">Cancel</button>

			</form>
		</dialog>

		<?php if ($mode == "list") : ?>
			<div id="list">
				<?php
				if (!isset($_GET['status'])) {
					$_GET['status'] = 0;
				}
				?>
				<h2><?php if (isset($STATUSES[$_GET['status']])) echo $STATUSES[$_GET['status']] . " "; ?>Issues</h2>
				<table border=1 cellpadding=5 width="100%" style="display: none;">
					<tr>
						<th>ID</th>
						<th>Title</th>
						<th>Created by</th>
						<th>Date</th>
						<th><acronym title="Watching issue?">W</acronym></th>
						<th>Last Comment</th>
						<th>Actions</th>
					</tr>
					<?php
					$count = 1;
					foreach ($issues as $issue) {
						$count++;
						echo "<tr class='p{$issue['priority']}'>\n";
						echo "<td>{$issue['id']}</a></td>\n";
						echo "<td><a href='?id={$issue['id']}'>" . htmlentities($issue['title'], ENT_COMPAT, "UTF-8") . "</a></td>\n";
						echo "<td>{$issue['user']}</td>\n";
						echo "<td>{$issue['entrytime']}</td>\n";
						echo "<td>" . ($_SESSION['tit']['email'] && strpos($issue['notify_emails'], $_SESSION['tit']['email']) !== FALSE ? "&#10003;" : "") . "</td>\n";
						echo "<td>" . ($issue['comment_user'] ? date("M j", strtotime($issue['comment_time'])) . " (" . $issue['comment_user'] . ")" : "") . "</td>\n";
						echo "<td><a href='?editissue&id={$issue['id']}'>Edit</a>";
						if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username'] == $issue['user']) echo " | <a href='?deleteissue&id={$issue['id']}' onclick='return confirm(\"Are you sure? All comments will be deleted too.\");'>Delete</a>";
						echo "</td>\n";
						echo "</tr>\n";
					}
					?>
				</table>
				<!-- new github like issue list -->

				<div class="issueList">
					<!-- example issue -->


					<?php
					foreach ($issues as $issue) {
						global $comments;

					?>
						<script>
							console.log(<?php echo json_encode($issue); ?>)
							console.log(<?php echo json_encode($comments); ?>)
						</script>
						<a href="?id=<?php echo $issue['id']; ?>">

							<div class="issueItem" data-ctx="true" data-issueId="<?php echo $issue['id']; ?>">
								<span class="issueStatus <?php
															if ($issue['priority'] == 1) {
																echo 'important';
															} else if ($issue['status'] == 1) {
																echo 'closed';
															} else {
																echo 'active';
															} ?>">
									<?php
									// if priority is high

									if ($issue['status'] == 1) { ?>
										<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-check-circle">
											<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
											<polyline points="22 4 12 14.01 9 11.01"></polyline>
										</svg>

									<?php } else if ($issue['priority'] == 1) { ?>
										<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-alert-circle">
											<circle cx="12" cy="12" r="10"></circle>
											<line x1="12" y1="8" x2="12" y2="12"></line>
											<line x1="12" y1="16" x2="12.01" y2="16"></line>
										</svg>

									<?php } else { ?>
										<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-disc">
											<circle cx="12" cy="12" r="10"></circle>
											<circle cx="12" cy="12" r="3"></circle>
										</svg>
									<?php }
									?>
								</span>
								<div class="itemBody">
									<div class="issueTitle">
										<span <?php
												if ($issue['priority'] == 1) {
													echo 'class="important"';
												} else if ($issue['priority'] == 3) {
													echo 'class="unimportant"';
												}

												?>>
											<?php echo $issue['title']; ?>
										</span>
										<span class="watchStatus">
											<?php
											if ($_SESSION['tit']['email'] && strpos($issue['notify_emails'], $_SESSION['tit']['email']) !== FALSE) {
											?>
												<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye">
													<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
													<circle cx="12" cy="12" r="3"></circle>
												</svg>
											<?php } else { ?>
												<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye-off">
													<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
													<line x1="1" y1="1" x2="23" y2="23"></line>
												</svg>
											<?php } ?>
										</span>
									</div>
									<div class="itemDetails">
										<div class="left">
											Opened on <span><?php echo $issue['entrytime']; ?></span> by <span><?php echo $issue['user']; ?></span>
										</div>
										<?php if ($issue['comment_count'] > 0) { ?>
											<div class="right">
												<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-message-circle">
													<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
												</svg>
												<span><?php echo $issue['comment_count']; ?></span>
											</div>
										<?php } ?>
									</div>
								</div>
							</div>
						</a>


						<div id="ctxmenuTemplate" class="ctxmenu" style="display: none;">

							<a onclick="document.getElementById('create').className='';document.getElementById('create').showModal();document.getElementById('title').focus();">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-plus">
									<line x1="12" y1="5" x2="12" y2="19"></line>
									<line x1="5" y1="12" x2="19" y2="12"></line>
								</svg>
								New
							</a>
							<br>
							<a href="#" data-ctxAction="edit">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-edit">
									<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
									<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
								</svg>
								Edit
							</a>
							<br>
							<a class="important" href="#" onclick="document.getElementById('confirmDelete').showModal();">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2">
									<polyline points="3 6 5 6 21 6"></polyline>
									<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
									<line x1="10" y1="11" x2="10" y2="17"></line>
									<line x1="14" y1="11" x2="14" y2="17"></line>
								</svg>
								Delete
							</a>
						</div>

						<dialog id="confirmDelete" style="width: 300px; height: 100px;">
							<form method="POST">
								<label>Are you sure you want to delete this issue?</label>
								<br>
								<div>
									<button onclick="document.getElementById('confirmDelete').close();" class="left">Cancel</button>
									<a href="#" data-ctxAction="delete" class="right important btn" id="confirmDeleteButton">Delete</a>
								</div>
							</form>
						</dialog>

						<script>
							oncontextmenu = (e) => {
								// check if a parent element has the data-ctx attribute in the hierarchy
								if (e.target.closest('.issueItem') && e.target.closest('.issueItem').dataset.ctx) {
									showCtxMenu(e, e.target.closest('.issueItem').dataset.issueid);
								}
							}

							function showCtxMenu(e, issueId) {
								// prevent default context menu
								e.preventDefault();
								console.log(issueId);

								// get the context menu element
								var ctxmenu = document.getElementById('ctxmenuTemplate');

								// get all ctx actions in the context menu
								var ctxActions = ctxmenu.querySelectorAll('[data-ctxAction]');
								// loop through all ctx actions
								ctxActions.forEach(ctxAction => {
									// get the action
									var action = ctxAction.dataset.ctxaction;
									if (action == 'edit') {
										ctxAction.href = `?editissue&id=${issueId}`;
									} else if (action == 'delete') {
										// ctxAction.href = `?deleteissue&id=${issueId}`;
									}
								});

								const confirmDeleteButton = document.getElementById('confirmDeleteButton');
								confirmDeleteButton.href = `?deleteissue&id=${issueId}`;

								// set the position of the context menu
								ctxmenu.style.top = `${e.clientY}px`;
								ctxmenu.style.left = `${e.clientX}px`;

								// show the context menu
								ctxmenu.style.display = 'block';

								// hide the context menu when clicked outside
								document.addEventListener('click', hideCtxMenu);
							}


							function hideCtxMenu() {
								const ctxmenu = document.getElementById('ctxmenuTemplate');
								ctxmenu.style.display = 'none';
								document.removeEventListener('click', hideCtxMenu);
							}
						</script>

					<?php } ?>
				</div>

			</div>
		<?php endif; ?>

		<?php if ($mode == "issue") : ?>
			<div id="show">
				<div class="issueContainer">
					<div class="issue">
						<h2><?php echo htmlentities($issue['title'], ENT_COMPAT, "UTF-8"); ?></h2>
						<p data-markdown="true"><?php echo nl2br(preg_replace("/([a-z]+:\/\/\S+)/", "<a href='$1'>$1</a>", htmlentities($issue['description'], ENT_COMPAT, "UTF-8"))); ?></p>
					</div>
					<hr />
					<br>
					<div class='between'>
						<div>Priority <select name="priority" onchange="location='<?php echo $_SERVER['PHP_SELF']; ?>?changepriority&id=<?php echo $issue['id']; ?>&priority='+this.value">
								<option value="1" <?php echo ($issue['priority'] == 1 ? "selected" : ""); ?>>High</option>
								<option value="2" <?php echo ($issue['priority'] == 2 ? "selected" : ""); ?>>Medium</option>
								<option value="3" <?php echo ($issue['priority'] == 3 ? "selected" : ""); ?>>Low</option>
							</select>
						</div>
						<div>
							Status <select name="priority" onchange="location='<?php echo $_SERVER['PHP_SELF']; ?>?changestatus&id=<?php echo $issue['id']; ?>&status='+this.value">
								<?php foreach ($STATUSES as $code => $name) : ?>
									<option value="<?php echo $code; ?>" <?php echo ($issue['status'] == $code ? "selected" : ""); ?>><?php echo $name; ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<form method="POST">
							<label></label>
							<label></label>
							<input type="hidden" name="id" value="<?php echo $issue['id']; ?>" />
							<?php
							if ($_SESSION['tit']['email'] && strpos($issue['notify_emails'], $_SESSION['tit']['email']) === FALSE)
								echo "<input type='submit' name='watch' value='Watch' />\n";
							else
								echo "<input type='submit' name='unwatch' value='Unwatch' />\n";
							?>
						</form>
					</div>
				</div>
				<script>
					console.log(<?php echo json_encode($issue); ?>);
				</script>
				<div class='clear'></div>
				<div id="comments">
					<?php
					if (count($comments) > 0) {
					?>
						<h3>Comments <a class="no-text-decoration" style="font-size: 1rem" href='<?php
																									// request url
																									echo  getUrl();
																									?>'>
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-refresh-cw">
									<polyline points="23 4 23 10 17 10"></polyline>
									<polyline points="1 20 1 14 7 14"></polyline>
									<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"></path>
								</svg>
							</a>
						</h3>
					<?php
					}
					foreach ($comments as $comment) {

						// With php code inline:

						// convert to human readable time, e.g. 1 minute / 2 hours / 4 days / 1 week / 2 months / 1 year 3 months
						$timeAgo = $comment['entrytime'];
						$timeAgo = timeToString($comment['entrytime']);

					?>

						<div class='comment' id='c<?php echo $comment['id']; ?>'>
							<p data-markdown="true"><?php echo nl2br(preg_replace("/([a-z]+:\/\/\S+)/", "<a href='$1'>$1</a>", htmlentities($comment['description'], ENT_COMPAT, "UTF-8"))); ?></p>
							<div class='comment-meta'>
								<span>
									<img src="https://www.gravatar.com/avatar/<?php echo $comment['gravatar']; ?>?s=20&d=retro" alt="Gravatar" class="gravatar" onerror="this.style.display = 'none';this.nextElementSibling.style.display = 'inline-block';">
									<svg class="userCirlce" style="display: none;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-user">
										<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
										<circle cx="12" cy="7" r="4"></circle>
									</svg>

									<em><?php echo $comment['user']; ?></em>
									posted
								</span>
								<em><a class="no-text-decoration" style="color: #666" href='#' title='<?php echo $comment['entrytime']; ?>'><?php echo $timeAgo; ?></a> ago</em>
								<span class='right'>
									<a href='#c<?php echo $comment['id']; ?>' title="Get comment link" class="no-text-decoration">
										<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-link">
											<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
											<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
										</svg>
									</a>
									<?php if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username'] == $comment['user']) : ?>

										<a class="important no-text-decoration" onclick="deleteModal(this)" data-deleteUrl='<?php echo $_SERVER['PHP_SELF']; ?>?deletecomment&id=<?php echo $issue['id']; ?>&cid=<?php echo $comment['id']; ?>' title="Delete comment">
											<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash">
												<polyline points="3 6 5 6 21 6"></polyline>
												<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
											</svg>
										</a>
									<?php endif; ?>
								</span>
							</div>
						</div>
					<?php
					}
					?>

					<dialog id="confirmDelete" style="width: 300px; height: 100px;">
						<form method="POST">
							<label>Are you sure you want to delete this comment?</label>
							<br>
							<div>
								<button onclick="document.getElementById('confirmDelete').close();" class="left">Cancel</button>
								<a href="#" data-ctxAction="delete" class="right important btn" id="confirmDeleteButton">Delete</a>
							</div>
						</form>
					</dialog>


					<script>
						! function(e, n) {
							"object" == typeof exports && "undefined" != typeof module ? module.exports = n() : "function" == typeof define && define.amd ? define(n) : (e = e || self).snarkdown = n()
						}(this, function() {
							var e = {
								"": ["<em>", "</em>"],
								_: ["<strong>", "</strong>"],
								"*": ["<strong>", "</strong>"],
								"~": ["<s>", "</s>"],
								"\n": ["<br />"],
								" ": ["<br />"],
								"-": ["<hr />"]
							};

							function n(e) {
								return e.replace(RegExp("^" + (e.match(/^(\t| )+/) || "")[0], "gm"), "")
							}

							function r(e) {
								return (e + "").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
							}
							return function t(o, a) {
								var c, s, l, g, u, p = /((?:^|\n+)(?:\n---+|\* \*(?: \*)+)\n)|(?:^``` *(\w*)\n([\s\S]*?)\n```$)|((?:(?:^|\n+)(?:\t| {2,}).+)+\n*)|((?:(?:^|\n)([>*+-]|\d+\.)\s+.*)+)|(?:!\[([^\]]*?)\]\(([^)]+?)\))|(\[)|(\](?:\(([^)]+?)\))?)|(?:(?:^|\n+)([^\s].*)\n(-{3,}|={3,})(?:\n+|$))|(?:(?:^|\n+)(#{1,6})\s*(.+)(?:\n+|$))|(?:`([^`].*?)`)|( \n\n*|\n{2,}|__|\*\*|[_*]|~~)/gm,
									f = [],
									i = "",
									d = a || {},
									m = 0;

								function h(n) {
									var r = e[n[1] || ""],
										t = f[f.length - 1] == n;
									return r ? r[1] ? (t ? f.pop() : f.push(n), r[0 | t]) : r[0] : n
								}

								function $() {
									for (var e = ""; f.length;) e += h(f[f.length - 1]);
									return e
								}
								for (o = o.replace(/^\[(.+?)\]:\s*(.+)$/gm, function(e, n, r) {
										return d[n.toLowerCase()] = r, ""
									}).replace(/^\n+|\n+$/g, ""); l = p.exec(o);) s = o.substring(m, l.index), m = p.lastIndex, c = l[0], s.match(/[^\\](\\\\)*\\$/) || ((u = l[3] || l[4]) ? c = '<pre class="code ' + (l[4] ? "poetry" : l[2].toLowerCase()) + '"><code' + (l[2] ? ' class="language-' + l[2].toLowerCase() + '"' : "") + ">" + n(r(u).replace(/^\n+|\n+$/g, "")) + "</code></pre>" : (u = l[6]) ? (u.match(/\./) && (l[5] = l[5].replace(/^\d+/gm, "")), g = t(n(l[5].replace(/^\s*[>*+.-]/gm, ""))), ">" == u ? u = "blockquote" : (u = u.match(/\./) ? "ol" : "ul", g = g.replace(/^(.*)(\n|$)/gm, "<li>$1</li>")), c = "<" + u + ">" + g + "</" + u + ">") : l[8] ? c = '<img src="' + r(l[8]) + '" alt="' + r(l[7]) + '">' : l[10] ? (i = i.replace("<a>", '<a href="' + r(l[11] || d[s.toLowerCase()]) + '">'), c = $() + "</a>") : l[9] ? c = "<a>" : l[12] || l[14] ? c = "<" + (u = "h" + (l[14] ? l[14].length : l[13] > "=" ? 1 : 2)) + ">" + t(l[12] || l[15], d) + "</" + u + ">" : l[16] ? c = "<code>" + r(l[16]) + "</code>" : (l[17] || l[1]) && (c = h(l[17] || "--"))), i += s, i += c;
								return (i + o.substring(m) + $()).replace(/^\n+|\n+$/g, "")
							}
						});
					</script>
					<script>
						function deleteModal(e) {
							const deleteUrl = e.dataset.deleteurl;
							const confirmDeleteButton = document.getElementById('confirmDeleteButton');
							confirmDeleteButton.href = deleteUrl;

							document.getElementById('confirmDelete').showModal();
						}

						function convertMarkdown() {
							// convert the comments to markdown
							const comments = document.querySelectorAll('[data-markdown]');
							comments.forEach(comment => {
								const commentId = comment.dataset.comment;
								const commentText = comment.innerHTML;
								const markdown = snarkdown(commentText);
								comment.innerHTML = markdown;
								retarget(comment);


							});
						}

						function retarget(el) {
							if (el.nodeName === 'A' && !el.target && el.origin !== location.origin) {
								el.setAttribute('target', '_blank');
								el.setAttribute('rel', 'noreferrer noopener');
							}
							for (let i = el.children.length; i--;) retarget(el.children[i]);
						}

						convertMarkdown();


						document.addEventListener("ajaxify:load", function(e) {
							// trigger event that pageName input value has changed

							// wait 100ms before triggering pageInit
							setTimeout(function() {
								convertMarkdown();
							}, 100);
						});
					</script>

					<div id="comment-create">
						<h4>Post a comment</h4>
						<form method="POST">
							<input type="hidden" name="issue_id" value="<?php echo $issue['id']; ?>" />
							<textarea name="description" rows="5" cols="50" style="width: 100%;" placeholder="Comment here..."></textarea>
							<label></label>
							<input type="submit" name="createcomment" value="Comment" />
						</form>
					</div>
				</div>
			</div>
		<?php endif; ?>
		<div id="footer">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-zap">
				<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
			</svg>
			Powered by <a href="https://github.com/JMcrafter26/tiny-issue-tracker" alt="Tiny Issue Tracker" target="_blank">Tiny Issue Tracker</a>
		</div>
	</div>
</body>

</html>