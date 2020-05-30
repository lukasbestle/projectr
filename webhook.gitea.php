<?php

/**
 * projectr
 * Tools for deployments and project management
 * 
 * Gitea frontend for deployments
 * 
 * @author Lukas Bestle <project-projectr@lukasbestle.com>
 * @copyright Copyright 2014 Lukas Bestle
 * @license MIT
 * @file webhook.gitea.php
 */

// 1. Configuration
// ====================

// Secret used as authentication (you have to set this in the Gitea project settings as well)
// ATTENTION: Make sure this is long and secure, as anyone could checkout any commit of your projects otherwise
define('SECRET', '<long token>');

// Path where you installed the scripts from the "bin" directory of this repository
// This is required, as PHP doesn't automatically use the environment and therefore your $PATH from your shell
define('TOOLKIT_PATH', '/home/<user>/bin');

// 2. Setup
// ====================

/**
 * This is an example webhook for Gitea projects.
 * It's fairly simple to set this script up on your server/Uberspace, here's how:
 * 
 * 1. Setup the toolkit, so you can access the tools from your SSH shell (more about that in README.md)
 * 2. Take a look at the settings above.
 * 	2.1. Seriously, have you set a secret? Yes? Alright.
 * 3. Place this script in a directory in ~/web/ (something like "~/web/hooks.example.com")
 * 4. You should now be able to access https://hooks.example.com/webhook.gitea.php
 *    This should output "Web hook event (X-Gitea-Event header) is missing from request.".
 * 
 * Now you can create projects and let this script deploy them for you:
 * 
 * 5. If your project is a private repository:
 *  5.1. Generate an SSH key by using `ssh-keygen`. The default values are alright for this purpose.
 *  5.2. Copy the contents of `~/.ssh/id_rsa.pub` to your clipboard and add it as "Deploy Key" in your project's Gitea settings interface.
 * 6. Create the project on the server by running either `project_add <path> <exact HTTPS clone url from Gitea>#<branch to deploy>` or
 *                                                       `site_add    <name> <exact HTTPS clone url from Gitea>#<branch to deploy>`.
 *    It's important to use the exact HTTPS url (not the SSH one) so this script can find the project!
 * 7. Add the URL (the one you tested in 4.) of the script to your project's web hooks (choose the type "Gitea").
 *    Make sure to add the secret configured above and select the "POST" method, the "application/json" type and the "Push Events" trigger.
 * 8. To test if it everything works, push some code to your Gitea repository.
 * 
 * Troubleshooting:
 *  - Use `tail -f <path to project>/logs/*.log`. This should tell you what went wrong.
 *  - Check if the TOOLKIT_PATH you set above is correct.
 *  - Check if you set the origin of your project to the correct URL.
 *  - Open the web hook URL you entered in Gitea in your browser and check if the output is "Web hook event (X-Gitea-Event header) is missing from request.".
 *  - Click on the pen icon next to your web hook in Gitea's settings interface. You should see a list of "recent deliveries".
 *    You can find the exact error message from this script in the "Response" tab of the delivery.
 */

// 3. Done
// The code starts here
// ====================

// We are always returning plain text
header('Content-Type: text/plain');

// Check if a secret has been set
if (SECRET === '<long token>') {
	http_response_code(500);
	die('No secret has been set in ' . basename(__FILE__) . ". This script won't work without one.");
}

// Check which event this is
if (isset($_SERVER['HTTP_X_GITEA_EVENT']) !== true) {
	http_response_code(400);
	die('Web hook event (X-Gitea-Event header) is missing from request.');
}
$event = $_SERVER['HTTP_X_GITEA_EVENT'];
switch ($event) {
	case 'push':
		echo "Received push event.\n";
		break;
	default:
		http_response_code(400);
		die("Received $event event, ignoring.");
}

// Get the request body
$input = false;
switch ($_SERVER['CONTENT_TYPE']) {
	case 'application/json':
		echo "Received JSON data in body.\n";
		$input = file_get_contents('php://input');
		break;
	case 'application/x-www-form-urlencoded':
		echo "Received URL-encoded form data in body.\n";
		$input = (isset($_POST['payload']) === true)? $_POST['payload'] : '';
		break;
	default:
		http_response_code(400);
		die("Don't know what to do with {$_SERVER['CONTENT_TYPE']} content type.");
} 
if (!$input) {
	http_response_code(400);
	die('No POST body sent.');
}

// Check if the authentication is valid
if (isset($_SERVER['HTTP_X_GITEA_SIGNATURE']) !== true) {
	http_response_code(401);
	die("Secret (X-Gitea-Signature header) is missing from request. Have you set a secret in Gitea's project settings?");
}
if (hash_equals(hash_hmac('sha256', $input, SECRET, false), $_SERVER['HTTP_X_GITEA_SIGNATURE']) !== true) {
	http_response_code(403);
	die('Secret (X-Gitea-Signature header) is wrong or does not match request body.');
}

// Parse payload
$payload = json_decode($input, true);
if (is_array($payload) !== true) {
	http_response_code(400);
	die('Invalid payload (no JSON?).');
}

// Get some interesting information from the payload
$url    = $payload['repository']['clone_url'];
$commit = $payload['after'];
$ref    = $payload['ref'];
if (preg_match('{(?:.*/){2}(.*)}', $ref, $matches) !== 1) {
	http_response_code(400);
	die('Invalid ref field (does not match regular expression "(?:.*/){2}(.*)").');
}
$branch = $matches[1];

// Debug
echo "Received commit hash \"$commit\" for repository URL \"$url\" (branch \"$branch\").\n";

// Determine the path to the projects file
$xdgProjectsFile = ($_ENV['XDG_CONFIG_HOME'] ?? ($_SERVER['HOME'] . '/.config')) . '/projectr/projects';
$projectsFile = is_file($xdgProjectsFile) === true ? $xdgProjectsFile : $_SERVER['HOME'] . '/.projects';

// Open the projects file and iterate through every project
$listPointer = fopen($projectsFile, 'r');
$exitCode = 0;
while (($project = fgets($listPointer)) !== false) {
	// Trim whitespace
	$project = trim($project);
	
	// Only deployable projects are interesting for us
	if (is_file($project . '/.origin') !== true || is_file($project . '/.branch') !== true) {
		continue;
	}
	
	// If there is a .origin and .branch file, check if they match
	if (trim(file_get_contents($project . '/.origin')) === $url && trim(file_get_contents($project . '/.branch')) === $branch) {
		// Found the right project
		echo "Found project at $project, running deploy script.\n";
		
		// Run deploy script (in the background, because Gitea doesn't like responses > 5sec by default)
		passthru('export PATH=' . escapeshellarg(TOOLKIT_PATH) . ':$PATH; project_deploy ' . escapeshellarg($project) . ' ' . escapeshellarg($commit) . ' &> /dev/null &', $exitCode);
		
		// If it didn't work, add debug statement
		if ($exitCode !== 0) {
			echo "Something didn't work.\n";
		}
	}
}

// Iterated through every project, exit
http_response_code(($exitCode === 0)? 200 : 500);
die('All done.');
