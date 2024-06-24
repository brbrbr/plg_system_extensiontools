# Extension Tools

- Scheduled Tasks
    - Check for updates of extensions & Joomla! Core and sends an email when available.
    - Auto update (configured) Extensions.
- CLI
    - Update Extensions using the information from the update server, just as when using the **Administrator** → **Extensions: Update**. No hasle to find the download link or append licence information.
    - Auto update (configured) Extensions.
    - Manual update/install using url, folder or package.


## Installation

- [Download the latest version of the plugin](https://github.com/brbrbr/plg_system_extensiontools/releases/latest)
- Install the plugin using `Upload & Install`
- The plugin should be enabled automaticity.

## Update notifications

- Set up a new Task Plugin `System -> Scheduled Tasks -> New -> All Updates Notification`
    - Add one or more recipients. These must be Super Users. If no recipient is set (or none of the selected recipients is a Super User any more) all Super Users with `Receive System Emails` enabled will receive an email
    - With `Send Once` to **Yes**, emails will only be sent once, until the list of available updates changes. Otherwise, an email is sent on each Task Execution
- Disable the Core update notifications task, if present.

## Extension update from CLI

### Notes
- All command are execute from the command line folder [ROOT]/cli
- Ensure the current user has write access, either directly or with a configured FTP Layer
- Always backup before you update

 ### Update Extensions

 This plugin introduces a new command `update:extensions:check`. This is like the command `extension:install` however it allows to use the extension id to update existiong extensions
 - use `php joomla.php update:extensions:check` to get a list of extensions with an update
 - then use `php joomla.php update:extensions:update --eid=<extension id>` to update an extension using the download information provided by the update server
 - to update an extension from a different source you use the options `--path` and `--url` which are identical to using them with `extension:install`

 ### Auto Update Extensions
 - go to the plugin settings page an select the extensions you want to enable auto updates for. You can select for Major,minor or patch updates. See [Semantic versioning](#semantic-versioning)
 - now use `php joomla.php update:extensions:update --all` to update extension with a new version

 ### Is it wise to auto update?

 Any change in an extension might break your site, which might be unnoticed with automatic updates. 

 In general automaticity updating extensions like language packs, editors and administrator tools like a backup-system should be save, as they most likely will not instantly kill your frontend when misconfigured. Updating extensions with a lot of overrides on your site is maybe not wise. Use your common sense for everything in between.


#### Semantic versioning.
Most extensions use semantic versioning, given a version number MAJOR.MINOR.PATCH, increment the:

1. MAJOR version might have incompatible API changes
2. MINOR version should add functionality in a backward compatible manner
3. PATCH version has backward compatible bug fixes


The Joomla extension update system does not distinguish between major, minor or patch updates. 

For automatic updates you can select the level to allow auto-updates for. 

Updating on patch level should be safe, on minor level safe enough. Major updates are probably best done manually.


Additional labels for pre-release and build metadata might be added as suffixes to the MAJOR.MINOR.PATCH format. These are not considered by the plugin.

## Error notifications

In the plugin settings you can configure email notification on (fatal) web errors. 

https://brokenlinkchecker.dev/extensions/extension-tools


##Found a bug?

Feel free to submit an issue or pull request.

Too much hasle, use the [contact form](https://brokenlinkchecker.dev/contact)
