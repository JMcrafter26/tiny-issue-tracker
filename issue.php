<?php
/*
 *      Tiny Issue Tracker (TIT) v2.0
 *      SQLite based, single file Issue Tracker
 *
 *      Copyright 2010-2013 Jwalanta Shrestha <jwalanta at gmail dot com>
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

	header("Location: {$_SERVER['PHP_SELF']}");
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

		// mail($to, $subject, $body, $headers);       // standard php mail, hope it passes spam filter :)
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

function insertCss() {
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
			width: 80vw;
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
		}

		.btn:hover {
			background: var(--button-hover);
		}

	</style>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/gh/chemerisuk/better-ajaxify/dist/better-ajaxify.min.js"></script>
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
			<form method="POST">
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
							<!-- 

        ?editissue&id={$issue['id']}
        ?deleteissue&id={$issue['id']}
						if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username'] == $issue['user']) echo " | <a href='?deleteissue&id={$issue['id']}' onclick='return confirm(\"Are you sure? All comments will be deleted too.\");'>Delete</a>";
						
     -->

	 <a onclick="document.getElementById('create').className='';document.getElementById('create').showModal();document.getElementById('title').focus();">
	 <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-plus"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
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
							<a class="important" href="#"  onclick="document.getElementById('confirmDelete').showModal();">
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
				<div class="issue">
					<h2><?php echo htmlentities($issue['title'], ENT_COMPAT, "UTF-8"); ?></h2>
					<p class=""><?php echo nl2br(preg_replace("/([a-z]+:\/\/\S+)/", "<a href='$1'>$1</a>", htmlentities($issue['description'], ENT_COMPAT, "UTF-8"))); ?></p>
				</div>
				<hr>
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

				<div class='clear'></div>
				<div id="comments">
					<?php
					if (count($comments) > 0) echo "<h3>Comments</h3>\n";
					foreach ($comments as $comment) {
						echo "<div class='comment' id='c" . $comment['id'] . "'><p>" . nl2br(preg_replace("/([a-z]+:\/\/\S+)/", "<a href='$1'>$1</a>", htmlentities($comment['description'], ENT_COMPAT, "UTF-8"))) . "</p>";
						echo "<div class='comment-meta'><em>{$comment['user']}</em> on <em><a href='#c" . $comment['id'] . "'>{$comment['entrytime']}</a></em> ";
						if ($_SESSION['tit']['admin'] || $_SESSION['tit']['username'] == $comment['user']) echo "<span class='right'><a href='{$_SERVER['PHP_SELF']}?deletecomment&id={$issue['id']}&cid={$comment['id']}' onclick='return confirm(\"Are you sure?\");'>Delete</a></span>";
						echo "</div></div>\n";
					}
					?>
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
			Powered by <a href="https://github.com/jwalanta/tit" alt="Tiny Issue Tracker" target="_blank">Tiny Issue Tracker</a>
		</div>
	</div>
</body>

</html>