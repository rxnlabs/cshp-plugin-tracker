# Cornershop Plugin Tracker
## Installation
Install this plugin by downloading the .zip file and uploading it to the WordPress admin.

Eventually this plugin will be installed using Satispress and it will have it's own update channel where updates to this plugin can be released to client sites.

This plugin will be installed on all client websites.

## Purpose
This plugin has two purposes.

### Track other plugins
The main purpose is for tracking the currently **installed** version of WordPress core, the themes, and the plugins on a WordPress website. The assets will be tracked in a `composer.json` file that is saved in the Uploads directory. This `composer.json` file should be tracked in the main Git repository for the website.

By tracking the installed versions of a WP core, third-party plugins, and third-party themes, these items can stop being tracked in the main Git repository for the website.

The `composer.json` file will be tracked in the main Git repo for the website along with a `README.md` that will list out the WordPress version, plugins, and themes _installed_ on the site.

This `composer.json` file will look like:

```json
{
  "name": "wordpress59playground/wordpress",
  "description": "Installed plugins and themes for the WordPress install https://wordpress.deyonte.cshp.co",
  "type": "project",
  "repositories": [{
    "type": "composer",
    "url": "https://wpackagist.org",
    "only": [
      "wpackagist-plugin/*",
      "wpackagist-theme/*"
    ]
  },
    {
      "type": "package",
      "package": {
        "name": "cshp/premium-plugins",
        "type": "wordpress-plugin",
        "version": "1.0",
        "dist": {
          "url": "https://wordpress.deyonte.cshp.co/wp-json/cshp-plugin-tracker/plugin/download?token=6532d19f-65ac-4e51-baf6-81960905f804",
          "type": "zip"
        }
      }
    }
  ],
  "require": {
    "cshp/wp": "6.1.1",
    "premium-plugin/backupbuddy": "8.7.4.0",
    "premium-plugin/cshp-support": "1.0.0",
    "premium-plugin/elementor-pro": "3.5.2",
    "premium-theme/blocksy-child": "1.0.0",
    "wpackagist-plugin/classic-editor": "1.6.2",
    "wpackagist-plugin/debug-bar": "1.1.3",
    "wpackagist-theme/blocksy": "1.8.53"
  },
  "extra": {
    "installer-paths": {
      "wordpress/plugins/${name}": [
        "type:wordpress-plugin"
      ],
      "wordpress/themes/${name}": [
        "type:wordpress-theme"
      ]
    }
  }
}
```

The `README.md` file will look like:
```markdown
## WordPress Version
- 6.1.1

## Themes Installed
- blocksy-child
- blocksy

## Plugins Installed
- backupbuddy (version 8.7.4.0)
- classic-editor (version 1.6.2)
- cshp-support (version 1.0.0)
- debug-bar (version 1.1.3)
- elementor-pro (version 3.5.2)

## WP-CLI Command to Install Plugins
`wp plugin install classic-editor --version="1.6.2" & wp plugin install debug-bar --version="1.1.3"`

## WP-CLI Command to Install Themes
`wp plugin install blocksy`

## Command Line to Zip Themes
Use command to zip premium themes if the .zip file cannot be created or downloaded
`zip -r premium-themes.zip blocksy-child`

## Command Line to Zip Plugins
Use command to zip premium plugins if the .zip file cannot be created or downloaded
`zip -r premium-plugins.zip backupbuddy cshp-support elementor-pro`
```

### Download the Premium themes and Premium plugins
The secondary capability of this plugin is to enable developers to be able to download a .zip file of the **premium** plugins and themes that are **activated** on the current website. 

Premium plugins and themes are defined as any plugin and theme that is not available for download on [wordpress.org](https://wordpress.org/). You can download the .zip file using the WordPress REST API URL:
- `https://insert-name-of-site.org/wp-json/cshp-plugin-tracker/plugin/download`
- `https://insert-name-of-site.org/wp-json/cshp-plugin-tracker/theme/download`

The **main** purpose of this capability is for spinning up a staging version of the live website on a demo server. Instead of cloning the Git repo for the website and the Git repo having all the plugins, themes, and WordPress core in that Git repo, you would:

1. Download the Git repo (the Git repo should contain the mu-plugins, plugins that are specific to the site, and the main theme)
2. Install WordPress core using WP CLI `wp core download --skip-content --version=insert-version-number-from-composer.json --force --path=.`
3. Install wordpress.org plugins using WP CLI or composer
4. Install wordpress.org themes using WP CLI or composer 
5. Download the premium plugins .zip file using the WP REST API URL using `wget` or `curl`.
6. Uzip the file plugins .zip and place the plugins into the `plugins/` folder
7. Download the premium themes .zip file using the WP REST API URL (the premium themes should usually be the parent theme if we are using a child theme) using `wget` or `curl`.
8. Uzip the themes .zip file and place themes into the `themes/` folder.

Most of these steps can be automated but we will need to modify the `setupsite` command to support this first.