=== Big File Uploads - Increase Maximum File Upload Size ===
Contributors: uglyrobot, jdailey, andtrev
Tags: increase file size limit, increase upload limit, max upload file size, post max size, upload limit, file upload, files uploader, ftp, video uploader, AJAX
Requires at least: 5.3
Tested up to: 6.8.2
Stable tag: 2.1.7
Requires PHP: 5.6
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enable large file uploads in the built-in WordPress media uploader via file chunking, and set maximum upload file size to any value based on user role.

== Description ==

**Big File Uploads lets you upload large media files directly to the media library with the WordPress media uploader. Increase your maximum upload size to any value – as large as your available disk space allows – and add file chunking to avoid server timeout errors.**

Bypass the upload limits on your server, set by your hosting provider, that prevent you from uploading large files to your media library.

Big File Uploads automatically detects upload limits set by your server or hosting provider, allows you to increase the maximum upload size, and prevents timeout errors by uploading files in chunks.

No messing with Apache/PHP initialization files or settings. Just activate the plugin, set the upload size as large as you like, and use the media uploader as you normally would.


### Big File Uploads Plugin Features

- Set maximum file upload file size as large as your hosts available storage
- Upload large files to your media without FTP or SFTP
- Built-in file chunking (upload large files in small pieces preventing timeout errors)
- Control maximum upload size limit
- Get smart recommendations based on available space in your temporary uploads directory
- Set maximum file size for each user role with upload capabilities (Administrator, Editor, Author)
- Set the max file size in Megabytes (MB) or Gigabytes (GB)
- Works with any server or hosting provider
- Upload any size file directly to a connected Infinite Uploads cloud account
- Super simple configuration and small plugin footprint that doesn't bog down WordPress
- Uploads directory disk utility for quickly analyzing storage usage in your media library

★★★★★
> “This is just perfect, EXACTLY what I needed to bypass the Cloudflare upload limit. Thank you very much!!” - [shamank](https://wordpress.org/support/users/shamank/)

★★★★★
> “Excellent plugin for changing the upload size for the Media Library uploads. Even though my host allowed me a bigger upload limit (from 64MB to 200MB) I could’nt make it work. This plugin solved my problem, fast and easy. Right after the installation, I changed the size, and I was able to upload my big file. Works like a charm, thanks guys.” - [ynskalad](https://wordpress.org/support/users/ynskalad/)

### Easily Increase Maximum File Uploads

Fix “The Uploaded File Exceeds the upload_max_filesize” error that is so common when you are trying to upload big files to your WordPress media library. Set a new max file size in Big File Uploads to bypass limitations set by the server or your host.


### Set Upload Size Based on User Role

Big File Uploads lets you set a new maximum upload size limit for all uploads or customize the maximum file upload size for each of your user roles with upload capabilities. Set custom upload limits for Administrators, Editors, Authors, or even custom roles.

### Uploads Disk Utility

The Big File Uploads plugin includes a media library disk utility that shows a breakdown of the files in your uploads directory by type and size. See how many images, videos, archives, documents, code, and other files (like audio) there are and how much space they're taking up.


### FTP/SFTP Client-free File Uploading

Upload files right to the WordPress media library without additional credentials and settings. Skip the protocol settings, server names, port numbers, usernames, long passwords, and private keys. Manage upload size and simplify your workflow for yourself or your clients.


### Widely Compatible

Other plugins simply rewrite the .htaccess or php.ini files in an attempt to adjust the server configuration which does not work with many hosts or causes timeouts. Big File Uploads changes how files are processed and uploads files in chunks (separate smaller pieces) before handing it off to WordPress making it universally compatible with most major hosting services.


### Wanna make your media library infinitely scalable? Move your big files and uploads directory to the cloud.

Big File Uploads is built to work with [Infinite Uploads](https://wordpress.org/plugins/infinite-uploads/) to make your site's upload directory infinitely scalable. A large WordPress media library can slow down your server and run up the cost of bandwidth and storage with your hosting provider. Move your uploads directory to the Infinite Uploads cloud to save on storage and bandwidth and improve site performance and security. Learn more about [Infinite Uploads cloud storage and content delivery network](https://infiniteuploads.com/?utm_source=wordpress.org&utm_medium=readme&utm_campaign=bfu_readme&utm_term=promo).

### Extend Big File Uploads to your forms!

[Big File Form Uploads](https://infiniteuploads.com/big-file-form-uploads/) is a paid add-on that  extends the functionality of increasing the maximum WordPress file upload size to your favorite form plugins for WordPress, including Contact Form 7, Gravity Forms, and Forminator! Set the limit in Big File Uploads, and that carries over to your file uploads in your forms to let your users upload big files! [Learn more about Big File Form Uploads!](https://infiniteuploads.com/big-file-form-uploads/)

### Privacy

This plugin does not collect or share any data. Site admins can optionally subscribe to email updates which is subject to our [Privacy Policy](https://infiniteuploads.com/privacy/?utm_source=wordpress.org&utm_medium=readme&utm_campaign=bfu_readme&utm_term=privacy).

== Frequently Asked Questions ==

= What is the biggest file size that can be uploaded? =

Uploads can be as large as available disk space for temporary files allows, or up to the maximum upload size limit you set in Settings -> Big File Uploads -> Uploading Files.

= Is Big File Uploads a free plugin? =

Yes all features of the Big File Uploads plugin are completely free and do not have a premium upgrade.

= Will Big File Uploads allow me to increase the upload limit for a form plugin (or other plugin) that allow users to upload on the frontend of my website? =

No. Frontend uploading built-in to plugins like Forminator, Gravity Forms, and WPForms do not use the same process as the WordPress media uploader on the backend of WordPress. Big File Uploads only works with the backend uploader or plugins that use the built-in media uploader code base to process the files.

= What media files can be uploaded? Are there any limitations with Big File Uploads? =

If you can upload it to the WordPress media library, Big File Uploads can process it. Big File Uploads can process everything from images and archive files to huge video and audio files.

= Is Infinite Uploads required for Big File Uploads to work? =

No. [Infinite Uploads](https://wordpress.org/plugins/infinite-uploads/) is an optional service to offload your media files to the cloud and make your WordPress website storage infinitely scalable. Perfect for sites that need to store many large file uploads.

= How can I report security bugs? =

You can report security bugs through the Patchstack Vulnerability Disclosure Program. The Patchstack team help validate, triage and handle any security vulnerabilities. [Report a security vulnerability.](https://patchstack.com/database/wordpress/plugin/tuxedo-big-file-uploads/vdp)

== Screenshots ==

1. Set maximum upload file size.
2. Customize upload size by user role.
3. Disk utility for analyzing storage usage.
4. Increase upload size for built-in file uploader.

== Changelog ==

2.1.7 [2025-09-02]
----------------------------------------------------------------------

- Update: WordPress 6.8.2 compatibility check

2.1.6 [2025-01-07]
----------------------------------------------------------------------
- 2025 update

2.1.5 [2025-01-07]
----------------------------------------------------------------------
- Copyright update

2.1.4 [2025-01-06]
----------------------------------------------------------------------
- FAQ update for security bug reporting

2.1.3 [2024-09-06]
----------------------------------------------------------------------
- Fix for Authenticated (Author+) Full Path Disclosure vulnerability in error messages. Props @netc4t

2.1.2 [2023-10-25]
----------------------------------------------------------------------
- Minor security improvement for dismissing the review notice (CSRF).

2.1.1 [2022-08-17]
----------------------------------------------------------------------
- Compatibility with Easy Digital Downloads plugin.
- Protect the temp directory from direct access.

2.1 [2022-08-14]
----------------------------------------------------------------------
- Can now handle files of any size, limited only by your disk space, not system temp directory size.

2.0.3 [2022-07-03]
----------------------------------------------------------------------
- Security fix: Prevent OS command injection in rare hosting configurations. props Marco Nappi.

2.0.2 [2022-02-03]
----------------------------------------------------------------------
- Fix: Conflicts with some theme builders like Themify.
- Fix: Fail with error message instead of showing success with partially uploaded big files missing chunks.
- Optimize default chunk size to limit requests.
- Add a review on wordpress.org timed notice
- Smoother Gutenberg editor support with a custom error message directing to use the media library uploader.

2.0.1 [2021-06-30]
----------------------------------------------------------------------
- Bug fix: Sometimes the upgrade notice showed in wrong places in the admin area. props Nick H.

2.0 - [2021-06-20]
----------------------------------------------------------------------
- Development and support now managed by Infinite Uploads
- Adds the ability to set maximum upload size by user role
- Adds Disk Utility module for analyzing storage usage
- Moves setting into new Big File Uploads tab under the WordPress Settings menu
- Updated UX design
- Replaces the confusing maximum retries and chunk size options with sane defaults that can be overridden via define
- Install Infinite Uploads and upload large files directly to your cloud account
- Improve notifications

1.2 - [2016-09-04]
----------------------------------------------------------------------
- Added maximum upload size limit setting.
- Stronger security: uploads now go through admin-ajax and check_admin_referer is called before any chunks are touched.

1.1 - [2016-01-12]
----------------------------------------------------------------------
- WordPress Multisite support (subdir, subdomain, and pre-WP3.5 networks)

1.0.1 - [2016-01-09]
----------------------------------------------------------------------
- Added fallback if the file info extension is missing

1.0 - [2015-12-20]
----------------------------------------------------------------------
- Initial release


== About Us ==
Infinite Uploads builds WordPress plugins and is a premium cloud storage provider and content delivery network (CDN) for all your WordPress media files. Learn more here:
[infiniteuploads.com](https://infiniteuploads.com/?utm_source=wordpress.org&utm_medium=readme&utm_campaign=bfu_readme&utm_term=about_us)

Learn how to manage large files on our blog:
[Infinite Uploads Blog, Tips, Tricks, How-tos, and News](https://infiniteuploads.com/blog/?utm_source=wordpress.org&utm_medium=readme&utm_campaign=bfu_readme&utm_term=blog)

[Contribute to the plugin's development on Github!](https://github.com/uglyrobot/big-file-uploads)

Want to use your WordPress site to train an AI chatbot to provide instant answers to your customers? Check out our sister project [DocsBot AI](https://docsbot.ai/?utm_source=wordpress.org&utm_medium=readme&utm_campaign=bfu_readme)!

Enjoy!

== Contact and Credits ==

Maintained by the cloud architects and WordPress engineers at [Infinite Uploads](https://infiniteuploads.com/?utm_source=wordpress.org&utm_medium=readme&utm_campaign=bfu_readme&utm_term=credits).

Big File Uploads was originally "Tuxedo Big File Uploads" created by Trevor Anderson ([@andtrev on WordPress.org](https://profiles.wordpress.org/andtrev/)), 2015-2021. Find Trevor on [GitHub](https://github.com/andtrev).
