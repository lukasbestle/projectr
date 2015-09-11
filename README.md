# Project management tools

> Tools for deployments and project management

## What this does

[Uberspace](https://uberspace.de) has a great routing setup for Apache sites: You place a directory or symbolic link with the name of the domain (like `www.example.com`) in `/var/www/virtual/<user>/` and every request to this domain is routed to the directory.

As a user with a [pretty complex routing setup](https://git.lukasbestle.com/groups/sites), I wanted to automate setting up new sites and updating them automatically from a Git repository. This toolset provides some CLI tools to make that possible.

### Example

Let's say you have a main site at `example.com` and another project at `subdomain.example.com`. You want the main site to be accessible from `example.net` as well. The main site is located at a GitHub repository from where everything should be deployed automatically.  
The result is the following setup:

	example.com/example.net -> Main site ("main_site")
	subdomain.example.com   -> Other project ("other_project")

This is a tutorial on how you would implement this setup with this toolset.

1. **Make sure you follow the setup instructions below.**
2. **Create the sites**
   
   ```bash
   # Create a new site and set the origin URL to the clone URL of your GitHub repository.
   site_add main_site https://github.com/organization/main.git
   
   # "other_project" is a local project and does not have an origin.
   site_add other_project
   ```
   
   You can now locate the sites at `~/web/sites/main_site` and `~/web/sites/other_project`.  
   Using `project_add` is similar, but it uses a custom project path – the headers of the files in `bin/` contain detailed usage information.
3. **Get the current state from your GitHub repository (other Git repositories work the same)**
   
   As "main_site" was setup using a clone URL, you can use `site_deploy <site> [<revision>]` to fetch and install the current state of your project automatically:
   
   ```bash
   site_deploy main_site
   ```
   
   If you omit the Git revision, the latest one is used instead. But if you want to deploy a specific revision, that's easy too:
   
   ```bash
   site_deploy main_site 78ca1d2fa93147b0...
   ```
   
   There is now a log of the deployment in `~/web/sites/main_site/logs/`, the project code at `~/web/sites/main_site/versions/00001-78ca1d2fa93147b0...` and a symlink to the code at `~/web/sites/main_site/current`. This symlink is automatically created if a deployment succeeded.
4. **Set the domains to make your project accessible**
   
   Now that your projects are installed and ready, you can let this toolset create links, so the (abstract) sites are made available at specific domains:
   
   ```bash
   # "main_site" should be available at example.com and example.net
   site_link main_site example.com
   site_link main_site example.net
   
   # "other_project" should be available at subdomain.example.com
   site_link other_project subdomain.example.com
   ```
   
   When deleting a site with `site_remove`, all of these domain links are automatically removed as well. You can also use `site_unlink` to unlink specific domains.

If you setup automatic deployments (see below), your sites are automatically updated to the newest revision. This is done by cloning or fetching the repository using `project_deploy` and updating the links, so the domain link always points to the latest revision.

Let's say you committed a mistake (haha) and you want to rollback to the last deployed (working) revision.  
Since our example site "main_site" is deployable (it has an origin repository), it stores a history of (by default) 5 versions and keeps the link to the last version. This means that you can easily return to the last version and manually to an even older version:

```bash
# Rollback to the last version
site_rollback main_site

# Manually rollback to an even older version
cd ~/web/sites/main_site
rm last
mv current last
ln -s versions/<version to restore> current
```

## Features

- Create and delete projects (general repositories like CLI tools) and sites (projects for the webserver)
- Set an origin Git repository and branch to get new versions from
- Create and delete links from one or multiple domains to a site
- Automatic Deployments from GitHub, GitLab or any other Git remote
- Run an optional setup script (`.postdeploy.sh` in the repository root) in each deployment's destination directory
- Reverse a deployable project to the last version in one step

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
	repo     # Clone of the project's Git repository
	├── …
	versions # Copies of the repository checked out to a specific revision
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

This is useful for projects that aren't using version control. You can still use domain linking with these though.

### Deployment setup

The tool `project_deploy` takes the full path to the project and the Git revision to install. Obviously, this is not very useful, but easy to use in deployment hook scripts.

You can find example PHP implementations for GitHub and GitLab web hooks in `webhook.github.php` and `webhook.gitlab.php`, but you can also create your own:

1. Write a script that receives web-hooks from GitHub, GitLab or similar and get the repository URL, commit SHA-1 and branch name of the event from the transmitted data.
2. Read the file `~/.projects`, which contains the paths to all known projects and sites, and iterate through it.
3. Open the projects `.origin` and `.branch` files. If they match the web-hook, run `project_deploy <path> <commit-sha1>` and you are done.

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
