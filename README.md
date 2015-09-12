![](logo.svg)

> A collection of tools to manage and automatically deploy sites or other repositories on a server

## What is this for?

Fully automatic deployments from Git repositories on GitHub or GitLab are not hard to do, but setting everything up in the first place can be quite difficult and annoying. There are services like [DeployBot](http://deploybot.com) that can do most of the setup work for you, but for small projects and sites such a service can be overkill.

This toolkit attempts to do as less magic as possible to help you to deploy your sites onto your server and to manage your sites with a few easy to understand command line tools written in Bash.

But there's more: My hoster of choice ([Uberspace](https://uberspace.de)) has a great Apache routing setup: You simply place a directory or symbolic link with the name of the domain (like `www.example.com`) in `/var/www/virtual/<user>/` and every request to the domain is routed to the corresponding directory automatically.
projectr makes it easy to create and remove these links to connect your deployed sites to one or more domains.

The tools have been tested on Uberspace and are especially great for their setup, but you can of course use all tools on any server with a proper shell (no Windows support, I'm sorry).

### Example and features

#### Introduction

Let's setup projectr for two example sites: A main site at `example.com` and `example.net` and another project at `subdomain.example.com`. The main site is located at a GitHub repository from where everything should be deployed automatically. The other site however won't use automatic deployments, as the users update the contents using FTP.  
The result is the following setup:

	example.com/example.net -> Main site ("main_site")
	subdomain.example.com   -> Other project ("other_project")

You can give the sites any name you want, they then become the unique identifier of the site.

#### Create the sites

**Before you can do this, you need to setup projectr itself. Please see the instructions below.**

```bash
# Create a new site and set the origin URL to the clone URL of your Git repository (GitHub in this case)
site_add main_site https://github.com/organization/main.git

# You can also declare which branch shall be deployable (master by default)
# site_add main_site https://github.com/organization/main.git#release

# "other_project" is a local project and does not have an origin
site_add other_project
```

You can now find the sites at `~/web/sites/main_site` and `~/web/sites/other_project`.

There's also `project_add`, which allows you to use a custom path. The `project_*` tools are very useful for repositories/projects that are no sites, for example for command line tools (I setup projectr using projectr on my servers, which is quite awesome).  
Generally, these tools work very similar. You can find more information in the scripts themselves.

#### Get the site code from the Git repository (our first deployment)
   
As `main_site` was setup using a clone URL, you can use `site_deploy <site> [<revision>]` to fetch and install the current state of your project automatically:

```bash
site_deploy main_site
```

If you omit the Git revision, the latest one is used instead. But if you want to deploy a specific revision (you should in production to make sure you deploy exactly the revision that triggered the deployment), that's easy too:

```bash
site_deploy main_site 78ca1d2fa93147b0...
```

There is now a log of the deployment in `~/web/sites/main_site/logs/`, the project code at `~/web/sites/main_site/versions/00001-78ca1d2fa93147b0...` and a symlink to the code at `~/web/sites/main_site/current`. This symlink is only automatically created if a deployment succeeded.

Our other project is not deployable. This means that you can put any files manually into `~/web/sites/other_project/current`, which is the web root of the site.

#### Deploy scripts

projectr supports post-deploy scripts that are run as part of the deployment process. Simply add an executable `.postdeploy.sh` Bash script at the top level of your repository. Their working directory is always the current deployment.

#### Store persistent data

Each project/site also has a `data` directory that never gets touched by deployments. You can use it to store configuration or persistent files.

#### Create domain links to your site
   
Now that your projects are installed and ready, you can use projectr to create links, so the (abstract) sites are made available at specific domains:

```bash
# "main_site" should be available at example.com and example.net
site_link main_site example.com
site_link main_site example.net

# "other_project" should be available at subdomain.example.com
site_link other_project subdomain.example.com
```

When deleting a site using `site_remove`, all of these domain links are automatically removed as well. You can also use `site_unlink` to unlink specific domains.

#### Webhooks and automatic deployments

If you setup automatic deployments using webhooks (see below), your sites are automatically updated after you push to your repository. This is done by cloning or fetching the repository using `project_deploy` and updating the links, so the domain link always points to the latest revision.

#### Rolling back to the last version

Let's say you committed a mistake (*badumm, tss*) and you want to rollback to the last deployed (working) revision.  
Since our example site `main_site` is deployable (it has an origin repository), projectr stores a history of (by default) 5 versions and keeps the link to the last deployed version. This means that you can easily return to the last version and manually to an even older version:

```bash
# Rollback to the last version
site_rollback main_site

# Manually rollback to an even older version
cd ~/web/sites/main_site
rm last
mv current last
ln -s versions/<version to restore> current
```

The automatic rollback does essentially the same thing. It only changes the link destination. No deployment files are lost in the process.

## Setup

1. Put this project wherever you want on the destination system and add the `bin` directory to your `$PATH`.
2. Create a backup of your `DocumentRoot` (`/var/www/virtual/<user>/` on Uberspace) and delete all contents. You can then setup all your sites using projectr.
3. Create a symlink to your `DocumentRoot` in `~/web` (this is what the `site_*` tools use):  
	`ln -s /var/www/virtual/$USER/ ~/web`
	
	You can also link to a subdirectory of your `DocumentRoot` if you want to manage the sites of this tool separately from your other sites. Completely different destination directories are also possible if you are using a different server setup.  
	**Please note that the `site_link` functionality doesn't do what you expect when using non-standard paths for `~/web`, because it places the links in `~/web`, not where Uberspace expects them.**
4. There is no step 4.

### Deployment setup

The tool `project_deploy` takes the full path to the project and the Git revision to install (`site_deploy` only takes the site name as you saw above). Obviously, this is not very useful, but easy to use in deployment hook scripts.

You can find example PHP implementations for GitHub and GitLab webhooks in `webhook.github.php` and `webhook.gitlab.php`, but you can also create your own if you use a different repository service:

1. Write a script that receives webhooks from the repository and gets the repository URL, commit SHA-1 and branch name of the event from the transmitted data.
2. Read the file `~/.projects`, which contains the paths to all known projects and sites, and iterate through it.
3. Open the project's `.origin` and `.branch` files. If they match the web-hook, run `project_deploy <path> <commit-sha1>` and you are done.

### Configuration

If you want to customize specific settings, you can create a Bash file at `~/.project.cnf` to override the default values. These are the possible settings and also the format of the file:

```bash
# Default branch to set if no one is given to `project_origin`/`site_origin`
# Only applies to newly created projects/sites
CONFIG_DEFAULT_BRANCH="master"

# Allowed length of the <revision> parameter of `project_deploy`
# Used to make deployments consistent (doesn't allow different hash lengths and therefore duplicated deployments)
# Default is a full-length SHA-1 hash, use 7 as value when using short hashes.
CONFIG_HASH_LENGTH=40

# Number of deployed versions to preserve (0 for infinite (be careful, that might use loads of storage space!))
# Versions older than the latest n versions get deleted automatically, logs are always preserved
CONFIG_PRESERVE_VERSIONS=5
```

## Background information

### Directory structure of a deployable project

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

### Directory structure of a non-deployable project

If you don't want to keep old versions of your code and use automatic deployment, simply leave out the `<origin>` parameter when creating the project and you end up with a structure like this:

	.domains # Site-specific: List of linked domains for this site
	.project # Empty file determining that this is a project
	current  # Directory for your project files
	├── <project files>
	data     # Never directly accessible by user agents and never overwritten
	└── <persistent non-VCS application data and configuration>

This is useful for projects that aren't using version control. You can still use domain linking with these though.

## Author

- Lukas Bestle <mail@lukasbestle.com>

## License

This project was published under the terms of the MIT license. You can find a copy [over at the repository](https://git.lukasbestle.com/tools/misc/blob/master/LICENSE.md).
