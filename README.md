# Google Sheet Event Calendar

**Contributors:** (Your Name or Company)
**Tags:** events, calendar, google sheets, csv, shortcode, upcoming events
**Requires at least:** 4.9
**Stable tag:** 1.0.1
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Displays a list of upcoming events fetched from a public Google Sheet (published as CSV) using a simple shortcode. Aims to mimic the basic list style of The Events Calendar's upcoming events view.

## Description

This plugin provides a lightweight way to display upcoming events on your WordPress site without needing a complex event management system. It fetches event data directly from a Google Spreadsheet that you maintain and have published to the web in CSV format.

The plugin uses a shortcode `[google_sheet_event_calendar]` which you can place in any post, page, or widget that processes shortcodes. It includes caching to improve performance and reduce load on the Google Sheet URL, refreshing the data every 6 hours.

**Features:**

* Simple shortcode `[google_sheet_event_calendar]`
* Configurable number of upcoming events to display.
* Fetches data from a public Google Sheet CSV link.
* Caches event data for 6 hours to improve performance.
* Filters out past events and sorts upcoming events chronologically.
* Basic CSS styling included to resemble common event lists.
* Uses English title if provided, otherwise falls back to Hungarian title.
* Respects WordPress date and time format settings for display.

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
    * Use a consistent date format in the `Kezdő DÁtum` column (e.g., `YYYY.MM.DD` or `YYYY-MM-DD`).
    * Use a consistent time format in the `Kezdő időpont` column (e.g., `HH:MM` - 24-hour format recommended).
    * The `English title of activity` (Column F) will be used if present; otherwise, `Program neve (Title)` (Column E) will be used.
    * Other columns (`Dátum vége`, `Záró időpont`, `Felelős`) are parsed but not displayed by default in the current version (except Location).
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
* Insert the following shortcode, replacing the placeholder URL with the actual public CSV URL you copied in the previous step:

    ```shortcode
    [google_sheet_event_calendar csv_url="YOUR_PUBLISHED_GOOGLE_SHEET_CSV_URL_HERE"]
    ```

* **Shortcode Parameters:**
    * `csv_url` (Required): The full public URL to your Google Sheet published as a CSV file.
    * `count` (Optional): The maximum number of upcoming events to display. Defaults to `5` if omitted. Example: `[google_sheet_event_calendar count="10" csv_url="..."]`

**Example:**

```shortcode
[google_sheet_event_calendar count="7" csv_url="[https://docs.google.com/spreadsheets/d/e/2PACX-...../pub?gid=0&single=true&output=csv](https://docs.google.com/spreadsheets/d/e/2PACX-...../pub?gid=0&single=true&output=csv)"]