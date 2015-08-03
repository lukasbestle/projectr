<?php

/**
 * Project management tools
 * Tools for deployments and project management
 * 
 * GitHub frontend for deployments
 * 
 * @author Lukas Bestle <mail@lukasbestle.com>
 * @copyright Copyright 2014 Lukas Bestle
 * @file webhook.github.php
 */

// 1. Configuration
// ====================

// Secret used as authentication (you have to set this in the GitHub project settings as well)
// ATTENTION: Make sure this is long and secure, as anyone could checkout any commit of your projects otherwise
define('SECRET', '<long token>');

// Path where you installed the scripts from the "bin" directory of this repository
// This is required, as PHP doesn't automatically use the environment and therefore your $PATH from your shell
define('TOOLKIT_PATH', '/home/<user>/bin');

// 2. Setup
// ====================

/**
 * This is an example webhook for GitHub projects.
 * It's fairly simple to set this script up on your server/Uberspace, here's how:
 * 
 * 1. Setup the toolkit, so you can access the tools from your SSH shell (more about that in README.md)
 * 2. Take a look at the settings above.
 * 	2.1. Seriously, have you set a secret? Yes? Alright.
 * 3. Place this script in a directory in ~/web/ (something like "~/web/hooks.example.com")
 * 4. You should now be able to access https://hooks.example.com/webhook.github.php
 *    This should output "Web hook event (X-Github-Event header) is missing from request.".
 * 
 * Then, you can easily create projects and let this script deploy them for you:
 * 
 * 5. Create the project on the server by running either `project_add <path> <exact HTTPS clone url from GitHub>#<branch to deploy>` or
 *                                                       `site_add    <name> <exact HTTPS clone url from GitHub>#<branch to deploy>`.
 *    It's important to use the exact HTTPS url (not the SSH one), so this script can find the project!
 * 6. Add the URL (the one you tested in 4.) of the script to your project's web hooks.
 *    Make sure to add the secret configured above and select "Just the push event".
 * 7. GitHub now sends a "ping" event, which is ignored by this script. To test if it all works, push some code to your GitHub repository.
 * 
 * Troubleshooting:
 *  - Use `tail -f <path to project>/logs/*.log`. This should tell you what went wrong.
 *  - Check if the TOOLKIT_PATH you set above is correct.
 *  - Check if you set the origin of your project to the correct URL.
 *  - Open the web hook URL you entered in GitHub in your browser and check if the output is "Web hook event (X-Github-Event header) is missing from request.".
 *  - Click on the pen icon next to your web hook in GitHub's settings interface. You should see a list of "deliveries".
 *    You can find the exact error message from this script in the "Response" tab of the delivery.
 */

// 3. Done
// The code starts here
// ====================

// We are always returning plain text
header('Content-Type: text/plain');

// Check if a secret has been set
if(SECRET == '<long token>') {
	http_response_code(400);
	die('No secret has been set in ' . basename(__FILE__) . '. This script won\'t work without one.');
}

// Check which event this is
if(!isset($_SERVER['HTTP_X_GITHUB_EVENT'])) {
	http_response_code(400);
	die('Web hook event (X-Github-Event header) is missing from request.');
}
$event = $_SERVER['HTTP_X_GITHUB_EVENT'];
switch($event) {
	case 'ping':
		die('Received ping event, ignoring.');
		break;
	case 'push':
		echo "Received push event.\n";
		break;
	default:
		http_response_code(400);
		die("Received $event event, ignoring.");
}

// Get the request body
$input = false;
switch($_SERVER['CONTENT_TYPE']) {
	case 'application/json':
		echo "Received JSON data in body.\n";
		$input = file_get_contents('php://input');
		break;
	case 'application/x-www-form-urlencoded':
		echo "Received URL-encoded form data in body.\n";
		$input = (isset($_POST['payload']))? $_POST['payload'] : '';
		break;
	default:
		http_response_code(400);
		die("Don't know what to do with {$_SERVER['CONTENT_TYPE']} content type.");
} 
if(!$input) {
	http_response_code(400);
	die('No POST body sent.');
}

// Check if the authentication is valid
if(!isset($_SERVER['HTTP_X_HUB_SIGNATURE'])) {
	http_response_code(401);
	die('Secret (X-Hub-Signature header) is missing from request. Have you set a secret in GitHub\'s project settings?');
}
if('sha1=' . hash_hmac('sha1', $input, SECRET, false) !== $_SERVER['HTTP_X_HUB_SIGNATURE']) {
	http_response_code(403);
	die('Secret (X-Hub-Signature header) is wrong or does not match request body.');
}

// Parse payload
$payload = json_decode($input, true);
if(!is_array($payload)) {
	http_response_code(400);
	die('Invalid payload (no JSON?).');
}

// Get some interesting information from the payload
$url    = $payload['repository']['clone_url'];
$commit = $payload['after'];
$ref    = $payload['ref'];
if(!preg_match('{(?:.*/){2}(.*)}', $ref, $matches)) {
	http_response_code(400);
	die('Invalid ref field (does not match regular expression "(?:.*/){2}(.*)").');
}
$branch = $matches[1];

// Debug
echo "Received commit hash \"$commit\" for repository URL \"$url\" (branch \"$branch\").\n";

// Open ~/.projects and iterate through every project
$listPointer = fopen($_SERVER['HOME'] . '/.projects', 'r');
$exitCode = 0;
while(($project = fgets($listPointer)) !== false) {
	// Trim whitespace
	$project = trim($project);
	
	// Only deployable projects are interesting for us
	if(!is_file($project . '/.origin') || !is_file($project . '/.branch')) continue;
	
	// If there is a .origin and .branch file, check if they match
	if(file_get_contents($project . '/.origin') == $url && file_get_contents($project . '/.branch') == $branch) {
		// Found the right project
		echo "Found project at $project, running deploy script.\n";
		
		// Run deploy script (in the background, because GitHub doesn't like requests > 30sec)
		passthru('export PATH=' . escapeshellarg(TOOLKIT_PATH) . ':$PATH; project_deploy ' . escapeshellarg($project) . ' ' . escapeshellarg($commit) . ' > /dev/null 2>&1 &', $exitCode);
		
		// If it didn't work, add debug statement
		if($exitCode !== 0) echo "Something didn't work.\n";
	}
}

// Iterated through every project, exit
http_response_code(($exitCode === 0)? 200 : 500);
die('All done.');
