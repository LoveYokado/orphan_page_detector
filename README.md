# Orphan Page Detector

**Contributors:** LoveYokado  
**Tags:** orphan pages, seo, maintenance, cleanup, pages  
**Requires at least:** 5.0  
**Tested up to:** 6.5  
**Stable tag:** 0.9.1
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Detects "orphan pages" that are not linked from any other page or post on your WordPress site.

## Description

Orphan Page Detector is a WordPress plugin designed to help site administrators find and manage "orphan pages" — pages that are published but have no incoming links from other posts or pages on the same site. These pages are often difficult for both users and search engines to discover.

This tool scans your entire site's content to identify all internal links and compares them against a list of all your published pages (and optionally, posts). The pages that are not found in the list of linked URLs are presented as orphan pages.

### Features

- **Scan for Orphan Pages:** Scans all published posts and pages to find pages that are not internally linked.
- **Filter Options:** Ability to exclude "Posts" from the scan and focus only on "Pages".
- **Bulk Actions:** Move multiple orphan pages to draft status with a single click.
- **CSV Export:** Download the list of detected orphan pages as a CSV file for further analysis or reporting.
- **Pagination:** Results are paginated for easy viewing on sites with many pages.

## Installation

1.  Upload the `orphan_page_detector` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Go to **Tools -> Orphan Pages** in your WordPress admin dashboard to view the plugin page.

## How to Use

1.  Navigate to **Tools -> Orphan Pages**.
2.  The plugin will automatically scan your site and display a list of any orphan pages it finds.
3.  Use the filter options at the top to exclude posts or change the number of items per page, then click "Apply".
4.  To move pages to draft, select the checkboxes next to the desired pages, choose "Move to Draft" from the bulk actions dropdown, and click "Apply".
5.  To download the results, click the "Download Results as CSV" button.

## Changelog

### 0.9.0

- Initial release.

### 0.9.1

- Added Force HTTPS and HTTP modes
- Added Detailed comments

## License

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.
