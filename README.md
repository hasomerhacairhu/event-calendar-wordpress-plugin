# Google Sheet Event Calendar

**Contributors:** Bedő Marci
**Tags:** events, calendar, google sheets, csv, shortcode, upcoming events, hungarian
**Requires at least:** 4.9
**Tested up to:** 6.5 (Update this as you test with newer WP versions)
**Stable tag:** 1.2.0
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html
**Plugin URI:** https://github.com/hasomerhacairhu/event-calendar-wordpress-plugin
**Author:** Bedő Marci
**Author URI:** https://somer.hu/

Displays upcoming events from a Google Sheet CSV using a shortcode. Styled for a specific Hungarian layout and allows control over cache duration.

## Description

This plugin provides a lightweight way to display upcoming events on your WordPress site without needing a complex event management system. It fetches event data directly from a Google Spreadsheet that you maintain and have published to the web in CSV format.

The plugin uses a shortcode `[google_sheet_event_calendar]` which you can place in any post, page, or widget that processes shortcodes. It includes caching to improve performance, and the cache duration is now configurable via a shortcode parameter.

**Features:**

* Simple shortcode `[google_sheet_event_calendar]`
* Configurable number of upcoming events to display.
* Fetches data from a public Google Sheet CSV link.
* **Configurable cache duration:** Control how long event data is cached (default is 6 hours). Option to disable cache.
* Filters out past events and sorts upcoming events chronologically.
* Specific CSS styling included for a two-column Hungarian layout (Date | Details).
* Prioritizes Hungarian event title (`Program neve (Title)`).
* Handles specific Hungarian date format (`YYYY.MM.DD.`) from the sheet.
* Attempts to use Hungarian locale for date formatting (requires WP language pack & server locale).

## Installation

1.  **Download:** Obtain the plugin file (`google-sheet-event-calendar.php`).
2.  **Upload via WordPress Admin:**
    * Navigate to your WordPress Admin Dashboard.
    * Go to `Plugins` -> `Add New`.
    * Click the `Upload Plugin` button at the top.
    * Click `Choose File` and select the `google-sheet-event-calendar.php` file you downloaded.
    * Click `Install Now`.
3.  **Activate:** After installation is complete, click the `Activate Plugin` button.

*Alternatively, you can manually upload the `google-sheet-event-calendar.php` file to your `/wp-content/plugins/` directory via FTP and then activate the plugin from the Plugins page in your WordPress admin.*

## Usage

**1. Prepare Your Google Sheet**

* Create a Google Spreadsheet to manage your events.
* **Crucially**, ensure the **first row** of your sheet contains the following headers **exactly** in columns A through H:
    ```
    A Kezdő DÁtum
    B Kezdő időpont
    C Dátum vége
    D Záró időpont
    E Program neve (Title)
    F English title of activity
    G Tervezett helyszín (Location)
    H Felelős (Manager)
    ```
* Fill in your event data below the header row.
    * Use the specific date format `YYYY.MM.DD.` (e.g., `2024.09.07.`) in the `Kezdő DÁtum` column.
    * Use a consistent time format (e.g., `HH:MM` - 24-hour format `10:00`, `18:00`) in the time columns.
    * The plugin will display the `Program neve (Title)` (Column E).
* **Publish the Sheet to the Web:**
    * In your Google Sheet, go to `File` -> `Share` -> `Publish to web`.
    * In the `Link` tab:
        * Select the specific sheet containing your event data from the first dropdown.
        * Select `Comma-separated values (.csv)` from the second dropdown.
    * Ensure `Entire document` or the correct sheet is selected.
    * Click the `Publish` button.
    * Confirm that you want to publish.
    * **Copy the generated URL.** This is the public link to your CSV data. *Note: Anyone with this link can view the published data.*

**2. Use the Shortcode**

* Go to the WordPress editor for the post, page, or widget where you want to display the event list.
* Insert the shortcode, customizing the parameters as needed:

    ```shortcode
    [google_sheet_event_calendar csv_url="YOUR_PUBLISHED_GOOGLE_SHEET_CSV_URL_HERE" count="5" cache_hours="6"]
    ```

* **Shortcode Parameters:**
    * `csv_url` (Required): The full public URL to your Google Sheet published as a CSV file.
    * `count` (Optional): The maximum number of upcoming events to display. Defaults to `5` if omitted.
    * `cache_hours` (Optional): The duration in hours for which the event data should be cached.
        * Defaults to `6` (hours) if omitted.
        * Accepts decimal values (e.g., `0.5` for 30 minutes).
        * Set to `0` to **disable caching** entirely (the sheet will be fetched and processed on every page load where the shortcode appears - use with caution as this can impact performance).

**Examples:**

* **Basic usage (default 5 events, 6-hour cache):**
    ```shortcode
    [google_sheet_event_calendar csv_url="[https://docs.google.com/spreadsheets/d/e/2PACX-...../pub?gid=0&single=true&output=csv](https://docs.google.com/spreadsheets/d/e/2PACX-...../pub?gid=0&single=true&output=csv)"]
    ```
* **Show 10 events, cache for 2 hours:**
    ```shortcode
    [google_sheet_event_calendar csv_url="YOUR_CSV_URL" count="10" cache_hours="2"]
    ```
* **Show 5 events, disable caching (fetch every time):**
    ```shortcode
    [google_sheet_event_calendar csv_url="YOUR_CSV_URL" cache_hours="0"]
    ```

The plugin will fetch the data (respecting the cache setting), filter for upcoming events based on the `Kezdő DÁtum` and current time, sort them, limit the count, and display them using the specified Hungarian layout.

## Changelog

### 1.2.0 - 2025-04-10
* **Feature:** Added `cache_hours` shortcode parameter to control cache duration (default 6 hours, 0 disables cache).
* **Improvement:** Added more robust checks for array and key existence during event data processing and rendering.
* **Fix:** Minor improvements to error logging messages.

### 1.1.1 - (Date of previous change)
* **Fix:** Changed shortcode registration back to `google_sheet_event_calendar`.
* **Fix:** Updated Text Domain to `google-sheet-event-calendar` throughout to match user-provided header.
* **Improvement:** Added padding for CSV rows with missing columns to prevent errors.
* Updated plugin header information based on user input.

### 1.1.0 - (Date of layout change)
* **Feature:** Implemented Hungarian layout based on screenshot (Date column | Details column).
* **Feature:** Prioritized Hungarian title (`Program neve (Title)`) for display.
* **Feature:** Added specific parsing for `YYYY.MM.DD.` date format.
* **Feature:** Attempt to set Hungarian locale for date formatting (`date_i18n`).
* **Update:** Adjusted CSS significantly for the new layout.
* **Update:** Renamed internal functions and handles (e.g., `_hu`) for clarity (Shortcode name temporarily changed then reverted in 1.1.1).

### 1.0.1 - (Date of previous change)
* Refined date/time parsing to handle more formats and date-only entries more gracefully.
* Added error logging for parsing issues.
* Improved robustness against rows with incorrect column counts.
* Minor code and comment cleanup.

### 1.0.0 - (Initial Release Date)
* Initial release.
* Provides `[google_sheet_event_calendar]` shortcode.
* Fetches events from Google Sheet CSV URL.
* Supports `count` and `csv_url` parameters.
* Implements 6-hour caching using Transients API.
* Includes basic CSS styling (original list style).