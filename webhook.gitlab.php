<?php

/**
 * projectr
 * Tools for deployments and project management
 * 
 * GitLab frontend for deployments
 * 
 * @author Lukas Bestle <project-projectr@lukasbestle.com>
 * @copyright Copyright 2014 Lukas Bestle
 * @license MIT
 * @file webhook.gitlab.php
 */

// 1. Configuration
// ====================

// Long token used as authentication (appended as GET parameter: https://hooks.example.com/webhook.gitlab.php?token=<AUTH_TOKEN>)
// ATTENTION: Make sure this is long and secure, as anyone could checkout any commit of your projects otherwise
define('AUTH_TOKEN', '<long token>');

// Path where you installed the scripts from the "bin" directory of this repository
// This is required, as PHP doesn't automatically use the environment and therefore your $PATH from your shell
define('TOOLKIT_PATH', '/home/<user>/bin');

// 2. Setup
// ====================

/**
 * This is an example webhook for GitLab servers.
 * It's fairly simple to set this script up on your server/Uberspace, here's how:
 * 
 * 1. Setup the toolkit, so you can access the tools from your SSH shell (more about that in README.md)
 * 2. Take a look at the settings above.
 * 	2.1. Seriously, have you set an auth token? Yes? Alright.
 * 3. Place this script in a directory in ~/web/ (something like "~/web/hooks.example.com")
 * 4. You should now be able to access https://hooks.example.com/webhook.gitlab.php?token=<AUTH_TOKEN>
 *    This should output "Authenticated, but no POST body sent.".
 * 
 * Now you can create projects and let this script deploy them for you:
 * 
 * 5. If your project is a private repository:
 *  5.1. Generate an SSH key by using `ssh-keygen`. The default values are alright for this purpose.
 *  5.2. Copy the contents of `~/.ssh/id_rsa.pub` to your clipboard and add it as "Deploy Key" in your project's GitLab settings interface.
 * 6. Create the project on the server by running either `project_add <path> <exact SSH url from GitLab>#<branch to deploy>` or
 *                                                       `site_add    <name> <exact SSH url from GitLab>#<branch to deploy>`.
 *    It's important to use the exact SSH url (not the HTTPS one) so this script can find the project!
 * 7. Add the URL (the one you tested in 4.) of the script to your project's web hooks.
 * 8. Hit "Test Hook" in GitLab's settings interface. The project should be deployed a few seconds later.
 * 
 * Troubleshooting:
 *  - Use `tail -f <path to project>/logs/*.log`. This should tell you what went wrong.
 *  - Check if the TOOLKIT_PATH you set above is correct.
 *  - Check if you set the origin of your project to the correct URL.
 *  - Open the web hook URL you entered in GitLab in your browser and check if the output is "Authenticated, but no POST body sent.".
 *  - If there is no log file and you can't find the reason yourself, it is complicated. ;)
 *    Debugging this is not easy (GitLab does not seem to store logs of its requests), but you can try to craft a JSON body like GitLab,
 *    send it to this script by using cURL or GUI tools like Rested and it will tell you exactly what went wrong.
 */

// 3. Done
// The code starts here
// ====================

// We are always returning plain text
header('Content-Type: text/plain');

// Check if an auth token has been set
if (AUTH_TOKEN === '<long token>') {
	http_response_code(500);
	die('No auth token has been set in ' . basename(__FILE__) . ". This script won't work without one.");
}

// Check if the authentication is valid
if (isset($_GET['token']) !== true) {
	http_response_code(401);
	die('Missing authentication.');
}
if (hash_equals(AUTH_TOKEN, $_GET['token']) !== true) {
	http_response_code(403);
	die('Invalid authentication.');
}

// Get the request body
$input = file_get_contents('php://input');
if (!$input) {
	http_response_code(400);
	die('Authenticated, but no POST body sent.');
}

// Parse payload
$payload = json_decode($input, true);
if (is_array($payload) !== true) {
	http_response_code(400);
	die('Invalid payload (no JSON?).');
}

// Get some interesting information from the payload
$url    = $payload['repository']['url'];
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
		
		// Run deploy script (in the background, because GitLab doesn't like responses > 10sec by default)
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
