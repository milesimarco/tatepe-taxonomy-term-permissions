=== TATEPE – Taxonomy Term Permissions ===
Contributors: milmor
Tags: taxonomy, terms, restrict, roles, permissions
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Choose the taxonomy terms in which specific roles or users cannot publish.

== Description ==

TATEPE lets you choose, for each role and for each single user, the terms of any taxonomy (categories, tags, custom taxonomies) in which they are not allowed to publish.

For a restricted user:

* restricted terms no longer appear in the editor (block and classic), in the terms screens and in the admin filters;
* a restricted term cannot be assigned to a post by any other means either (quick edit, REST API, XML-RPC): the save is refused with an error and nothing is written;
* posts assigned to a restricted term no longer appear in the admin post lists.

Rules set for a single user replace the rules of their roles. Users who can manage options (administrators) are never restricted.

The plugin only limits publishing: it does not change what visitors see on the front end, and it does not prevent a restricted user from reading a post.

Based on "Restrict Taxonomies" by Mateus Souza.

== Installation ==

1. Upload the `tatepe-taxonomy-term-permissions` folder to the `/wp-content/plugins/` directory, or install the plugin from the Plugins screen.
1. Activate the plugin through the Plugins screen.
1. Go to Settings > TATEPE and check the restricted terms for each role or user.

== Frequently Asked Questions ==

= Does it work with custom roles and custom taxonomies? =

Yes. Every editable role and every taxonomy with an admin interface is listed.

= Will this prevent my visitors from seeing posts? =

No. The front end is not affected.

= What happens when both a role and a user have rules? =

The user rules win. As soon as at least one term is checked for a user in the Users tab, only those terms are restricted for that user and the rules of their roles are ignored. A user with no terms checked follows the rules of their roles.

= Can I lock myself out? =

No. Users who can manage options are never restricted, and the Reset button removes every rule.

= Can I restrict the default category? =

Better not. WordPress assigns the default category to every post saved without a category, whoever the author is, so a restricted user could still end up publishing in it.

= Are the rules removed when I delete the plugin? =

Only if you ask for it: enable "Delete all the rules when the plugin is deleted" at the bottom of the settings page. Otherwise the rules are kept, and you find them again if you reinstall the plugin.

== Changelog ==

= 1.0.0 =
* First public release.
