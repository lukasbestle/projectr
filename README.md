# Project management tools

> Tools for deployments and project management

## What this does

[Uberspace](https://uberspace.de) has a great routing setup for Apache sites: You place a directory or symbolic link with the name of the domain (like `www.example.com`) in `/var/www/virtual/<user>/` and every request to this domain is routed to the directory.

As a user with a [pretty complex routing setup](https://git.lukasbestle.com/groups/sites), I wanted to automate setting up new sites and updating them automatically from a Git repository. This toolset provides some CLI tools to make that possible.

## Features

- Create and delete projects (general) and sites (projects for the webserver)
- Set an origin Git repository and branch to get new versions from
- Create and delete links from one or multiple domains to a site
- Get a specific new revision from the Git repository, run a setup script (`.postdeploy.sh` in the repository root) and point a link to the new version
- Reverse a deployable project to the last version

### Directory structure of a project

Every project/site follows this directory structure:

	.branch  # Branch to allow deployments for (see "Deployment setup")
	.domains # Site-specific: List of linked domains for this site
	.origin  # URL of the origin repository
	.project # Empty file determining that this is a project
	current  # Symlink to the currently active version in versions/
	├── <project files>
	data     # Never directly accessible by user agents and never overwritten
	├── <persistent non-VCS application data and configuration>
	last     # Symlink to the last active version in versions/
	├── <project files>
	logs     # Logs of all deployments
	├── <commit-hash>.log
	├── <commit-hash>.log
	versions # Fully separate Git repositories of the project
	├── <id>-<commit-hash>
	│   └── <project files>
	└── <id>-<commit-hash>
	    └── <project files>

### Usage without any kind of deployment

If you don't want to keep old versions of your code and use automatic deployment, simply leave out the `<origin>` parameter and you end up with a structure like this:

	.domains # Site-specific: List of linked domains for this site
	.project # Empty file determining that this is a project
	current  # Directory for your project files
	├── <project files>
	data     # Never directly accessible by user agents and never overwritten
	└── <persistent non-VCS application data and configuration>

### Deployment setup

The tool `project_deploy` takes the full path to the project and the Git revision to install. Obviously, this is not very useful, but easy to use in deployment hook scripts:

1. Write a script that receives web-hooks from GitHub, GitLab or similar and get the repository URL, commit SHA-1 and branch name of the event from the transmitted data.
2. Read the file `~/.projects`, which contains the paths to all known projects and sites, and iterate through it.
3. Open the projects `.origin` and `.branch` files. If they match the web-hook, run `project_deploy <path> <commit-sha1>` and you are done.

You can find example PHP implementations for GitHub and GitLab web hooks in `webhook.github.php` and `webhook.gitlab.php`.

## Setup

1. Put this project wherever you want on the destination system and add the `bin` directory to your `PATH`.
2. Create a backup and clean your `DocumentRoot` (`/var/www/virtual/<user>/` on Uberspace), as you probably want to manage everything with this toolset.
3. Create a symlink to your `DocumentRoot` in `~/web` (this is what the `site_*` tools use):  
   `ln -s /var/www/virtual/$USER/ ~/web`
   
   You can also link to a subdirectory of your `DocumentRoot` if you want to manage the sites of this tool separately from your other sites. Please note that the `site_link` functionality does not work without manually linking the resulting links to your `DocumentRoot` when using non-standard paths for `~/web`.
4. Have fun with the tools in `bin`.

## Configuration

If you want to customize specific settings, you can create a Bash file at `~/.project.cnf` overriding the default values at every run of the tools. These are the possible settings and also the format of the file:

	# Default branch to set if no one is given to `project_origin`
	CONFIG_DEFAULT_BRANCH="master"
	
	# Allowed length of the <revision> parameter of `project_deploy`
	# Used to make deployments consistent (doesn't allow different hash lengths and therefore duplicated deployments)
	# Default is a full-length SHA-1 hash, use 7 as value when using short hashes.
	CONFIG_HASH_LENGTH=40
	
	# Number of deployed versions to preserve (0 for infinite (be careful, that might use loads of storage space!))
	# Versions older than the latest n versions get deleted automatically, logs are always preserved
	CONFIG_PRESERVE_VERSIONS=5

## Author

- Lukas Bestle <mail@lukasbestle.com>

## License

This project was published under the terms of the MIT license. You can find a copy [over at the repository](https://git.lukasbestle.com/tools/misc/blob/master/LICENSE.md).
