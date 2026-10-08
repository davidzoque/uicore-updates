# UiCore Updates

Shows UiCore Pro theme updates as regular WordPress updates, so they appear in
**Dashboard > Updates** and in site management tools like **Modular DS**, and can
be launched from there. White-label themes are supported.

Out of the box, UiCore Pro updates only show up in the theme's own Updates tab,
so with several sites you have to log into each one and click Update by hand.
This plugin bridges that gap.

> Not an official UiCore plugin. Keep a backup handy, as with any update.

## Features

- Adds the UiCore Pro update to the standard WordPress theme update list, so any
  tool that reads that list can see it and run it.
- Works with white-label themes: the theme keeps its folder and its name.
- After the theme updates, installs the UiCore Framework bundled in the new
  version (plus Element Pack and MetForm Pro if they are active), the same way
  UiCore's own Update button does.
- Rebuilds the theme CSS over HTTPS. When UiCore regenerates it outside HTTPS, it
  writes the icon font URL with `http://`, the browser blocks it and the theme
  icons show up as empty squares.
- Deletes `uicore-theme-update.zip`, the package that UiCore's updater leaves in
  `wp-content/uploads`.
- Clears the WP Compress and LiteSpeed Cache caches after the update.
- Keeps UiCore's internal templates (Theme Builder headers, footers and popups,
  and the brand kit) out of the sitemap and adds `noindex` to them, so Google
  doesn't index them as standalone pages. Works with the WordPress sitemap,
  Yoast SEO and Rank Math.
- Your license token never leaves your site: the update list only holds a
  placeholder, and the real download link is built on your server when the
  update runs.
- Updates itself from this repository's releases.

## Requirements

- UiCore Pro (or a white-label version of it), connected to its license.
- WordPress 6.5 or newer, PHP 7.4 or newer.

## Installation

1. Download `uicore-updates.zip` from the
   [latest release](https://github.com/davidzoque/uicore-updates/releases/latest).
2. In WordPress go to **Plugins > Add New > Upload Plugin**, upload the zip and
   activate it.

That's all: there are no settings. The plugin's row on the Plugins screen shows
the installed theme version, the latest published version and when the last
update ran.

## How it works

1. Every 6 hours it checks the latest UiCore Pro version at
   `https://api.uicore.co/v1/uicore-pro/updates`. If the theme is behind, it adds
   the update to WordPress's theme update list.
2. When the update runs, it downloads the package from UiCore with your site's
   license connection. UiCore delivers the zip already named after your theme,
   white label included.
3. **Safety check:** if the package comes with a different folder name than your
   installed theme, the update is cancelled before WordPress removes the old theme
   folder, so the site is never left without its parent theme.
4. In a separate request (via WP-Cron, as UiCore's panel does) it installs the
   plugins bundled in the new theme version.
5. On the next HTTPS request it rebuilds the theme CSS, removes the leftover zip
   and clears the caches. Every 12 hours it also checks that the CSS has no
   `http://` URLs for your domain and rebuilds it if needed.

## Compatibility

Tested with Modular DS, including its safe updates with visual comparison. Since
the plugin uses the standard WordPress update list, other managers such as
MainWP, ManageWP or WP Umbrella should also see the updates, but they have not
been tested yet. Reports are welcome in
[Issues](https://github.com/davidzoque/uicore-updates/issues).

UiCore's own Updates tab keeps working as before.

## Uninstall

Deactivate and delete the plugin. UiCore updates go back to showing only in the
theme's Updates tab.

## For maintainers

To publish a new version, bump `Version:` in the plugin header and
`DOX_UU_VERSION`, commit to `main` and push a `vX.Y.Z` tag. The GitHub workflow
builds `uicore-updates.zip` and creates the release. Releases have no notes on
purpose: some plugins bundle a copy of Plugin Update Checker without Parsedown,
and it crashes when a release has notes.

## License

GPL-2.0-or-later.
