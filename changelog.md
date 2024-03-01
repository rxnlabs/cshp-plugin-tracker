# Changelog

## [1.1.1] - 2024-03-01

### Added

- Added the ability to install the premium plugins using the stored site key instead of needing to provide the live URL. The site key will be stored in the database and will be used to translate the site key into a domain.
- Added the ability to install the premium plugins and the wordpress.org plugins at the same time instead of needing to run two different commands to install both.

### Fixed

- Fixed bug that was preventing the premium plugins from being backed up to CPR.
- Fixed bug that was preventing old premium plugin zip files from being deleted when the plugins were updated.

## [1.1.0] - 2024-02-27

### Added

- Added the ability to selectively install the premium plugins using the WP CLI command `wp cshp-pt plugin-install`
- Added the ability to search for and install plugin backups from the Cornershop Plugin Recovery website from the WordPress admin.
- Added the ability to create multiple versions of the premium plugins archive for different plugins. Instead of a backup containing all plugins, a backup can contain specific plugins if not all plugins are needed by the requesting website.

## [1.0.32] - 2023-10-04

### Added

- Added more plugins to the list of known premium plugins.

### Fixed

- Fixed bug that would throw a fatal error when deleting a theme and automatically regenerating the composer.json file.

## [1.0.31] - 2023-08-21

### Fixed

- Increase the timeout of the WP_HTTP request that is responsible for backing up the plugin.

## [1.0.3] - 2023-08-21

### Added

- Added the ability to bulk install the premium plugins and the premium theme using WP CLI.
- Backup the currently installed version of the premium plugins during a nightly cron job.

### Changed

- Changed the URL where future plugin updates will take place. Changed the URL from the Cornershop staging server to the https://plugins.cornershopcreative.com website.

## [1.0.2] - 2023-08-03

### Added

- Added a WP CLI command to bulk install the wordpress.org plugins and themes that are available on the site.

### Changed

- In the generated README.md file and when bulk install plugins and themes, passed the flag —skip-plugins and —skip-themes to prevent a plugin or theme conflict from interfering with the install process.

## [1.0.11] - 2023-08-01

### Added

- Show the command to download the premium plugins using wget on the plugin settings page.
- Show the command to download the public plugins on the plugin settings page.
- Add the —force flag to the WP CLI command to download the public plugins so that if the plugin is already installed, WP CLI will overwrite the current version of the public with the one from the command.

### Fixed

- Call the globally namespaced function as_has_scheduled_action to prevent undefined function error.

## [1.0.1] - 2023-08-01

### Changed
- Add the —force flag to the WP CLI command to download the public plugins so that if the plugin is already installed, WP CLI will overwrite the current version of the public with the one from the command.
- Moved the known premium plugins and premium themes to a separate file.

### Fixed

- Fixed bug when generating the plugins and zip file where the first character in each file name was missing.
- Fixed bug that would generate the composer.json file when the plugins were deactivated. If multiple plugins were deactivated at the same time, the composer.json file would be generated for each deactivation.
- Fixed bug that would cause the log not to purge when the number of logs reaches past 200.

## [1.0.0] - 2023-04-12

### Added

- Initial release.