<?php
/**
 * Plugin Name:       Google Sheet Event Calendar (HU Layout)
 * Plugin URI:        https://example.com/ (Optional: Link to plugin info)
 * Description:       Displays upcoming events from a Google Sheet CSV using a shortcode, styled for a specific Hungarian layout.
 * Version:           1.1.0
 * Author:            Your Name or Company
 * Author URI:        https://example.com/ (Optional: Link to your website)
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       google-sheet-event-calendar-hu
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define constants for column indices based on provided headers
define('GSEC_COL_START_DATE', 0);      // A Kezdő DÁtum
define('GSEC_COL_START_TIME', 1);      // B Kezdő időpont
define('GSEC_COL_END_DATE', 2);        // C Dátum vége
define('GSEC_COL_END_TIME', 3);        // D Záró időpont
define('GSEC_COL_TITLE_HU', 4);        // E Program neve (Title)
define('GSEC_COL_TITLE_EN', 5);        // F English title of activity (Kept for data structure, but not used in output)
define('GSEC_COL_LOCATION', 6);        // G Tervezett helyszín (Location) (Kept for data structure, but not used in output)
define('GSEC_COL_MANAGER', 7);         // H Felelős (Manager) (Kept for data structure, but not used in output)

/**
 * Load plugin textdomain for internationalization.
 */
function gsec_load_textdomain() {
    load_plugin_textdomain( 'google-sheet-event-calendar-hu', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'gsec_load_textdomain' );


/**
 * Registers the shortcode [google_sheet_event_calendar].
 */
function gsec_register_shortcode() {
    // Use a slightly different shortcode name to avoid conflict if the old one exists
    add_shortcode( 'google_sheet_event_calendar_hu', 'gsec_render_shortcode_hu' );
    // Optional: Add alias for the old shortcode name if needed for backward compatibility
    // add_shortcode( 'google_sheet_event_calendar', 'gsec_render_shortcode_hu' );
}
add_action( 'init', 'gsec_register_shortcode' );

/**
 * Renders the event calendar shortcode output (Hungarian Layout).
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output for the event list.
 */
function gsec_render_shortcode_hu( $atts ) {
    // Define default attributes and merge with user attributes
    $atts = shortcode_atts(
        array(
            'count'   => 5,            // Default number of events to show
            'csv_url' => '',           // Default empty CSV URL
        ),
        $atts,
        'google_sheet_event_calendar_hu' // Use the new shortcode name here too
    );

    // Sanitize attributes
    $event_count = max(1, intval( $atts['count'] )); // Ensure count is at least 1
    $csv_url     = esc_url_raw( trim( $atts['csv_url'] ) ); // Clean the URL

    // Validate CSV URL
    if ( empty( $csv_url ) || ! filter_var( $csv_url, FILTER_VALIDATE_URL ) ) {
        // Using __() for potential translation if language files are provided
        return '<p style="color: red;">' . esc_html__( 'Hiba: Kérjük, adjon meg egy érvényes Google Táblázat CSV URL-t a shortcode-ban.', 'google-sheet-event-calendar-hu' ) . '</p>';
    }

    // --- Caching Logic ---
    $cache_key      = 'gsec_event_data_hu_' . md5( $csv_url ); // Slightly different cache key
    $cached_events  = get_transient( $cache_key );
    $fetched_events = false; // Initialize as false

    if ( false === $cached_events ) {
        // Cache is expired or doesn't exist, fetch fresh data
        $fetched_events = gsec_fetch_and_parse_csv_hu( $csv_url ); // Use updated parsing function if needed (name kept same for now)

        if ( is_wp_error( $fetched_events ) ) {
            // If fetching failed, return the error message
             return '<p style="color: red;">' . sprintf(
                esc_html__( 'Hiba a CSV lekérésekor vagy feldolgozásakor (%s):', 'google-sheet-event-calendar-hu' ),
                esc_html( $fetched_events->get_error_code() )
            ) . ' ' . esc_html( $fetched_events->get_error_message() ) . '</p>';
        }

        // Store the fetched data in the cache for 6 hours
        set_transient( $cache_key, $fetched_events, 6 * HOUR_IN_SECONDS );

    } else {
        // Use data from cache
        $fetched_events = $cached_events;
    }

    // --- Data Processing ---
    if ( empty( $fetched_events ) ) {
         return '<p>' . esc_html__( 'Nem található eseményadat a táblázatban vagy a gyorsítótárban.', 'google-sheet-event-calendar-hu' ) . '</p>';
    }

    // Filter for upcoming events and sort them
    $upcoming_events = gsec_filter_and_sort_events_hu( $fetched_events ); // Use updated filtering/sorting function

    // Limit the number of events
    $display_events = array_slice( $upcoming_events, 0, $event_count );

    // --- Rendering ---
    if ( empty( $display_events ) ) {
        return '<p>' . esc_html__( 'Nincsenek közelgő események.', 'google-sheet-event-calendar-hu' ) . '</p>';
    }

    // Enqueue styles for the calendar
    gsec_enqueue_styles_hu(); // Use updated styles function

    return gsec_generate_html_hu( $display_events ); // Use updated HTML generation function
}

/**
 * Fetches and parses the CSV data from the given URL.
 * (No changes needed here from version 1.0.1, assuming column structure is the same)
 *
 * @param string $csv_url The URL of the Google Sheet CSV.
 * @return array|WP_Error Array of event data on success, WP_Error on failure.
 */
function gsec_fetch_and_parse_csv_hu( $csv_url ) {
    $response = wp_remote_get( $csv_url, array( 'timeout' => 15 ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'fetch_error', __( 'Nem sikerült lekérni az adatokat az URL-ről.', 'google-sheet-event-calendar-hu' ), $response->get_error_message() );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        return new WP_Error( 'fetch_error_code', sprintf( __( 'A %d HTTP státuszkód érkezett az URL lekérésekor.', 'google-sheet-event-calendar-hu' ), $http_code ) );
    }

    $csv_data = wp_remote_retrieve_body( $response );

    if ( empty( $csv_data ) ) {
         return new WP_Error( 'empty_csv', __( 'A letöltött CSV fájl üres.', 'google-sheet-event-calendar-hu' ) );
    }

    // Parse CSV data
    $lines = explode( "\n", trim( $csv_data ) );
    $header = null;
    $events = array();

    if (count($lines) < 2) {
        return new WP_Error( 'no_data_rows', __( 'A CSV nem tartalmaz adatsorokat (csak fejlécet vagy üres).', 'google-sheet-event-calendar-hu' ) );
    }

    foreach ( $lines as $index => $line ) {
        if ( empty( trim($line) ) ) continue;
        $row = str_getcsv( trim( $line ) );

        if ( $index === 0 ) {
            $header = $row;
            continue;
        }

        if ( count( $row ) < max( GSEC_COL_START_DATE, GSEC_COL_START_TIME, GSEC_COL_END_DATE, GSEC_COL_END_TIME, GSEC_COL_TITLE_HU, GSEC_COL_TITLE_EN, GSEC_COL_LOCATION, GSEC_COL_MANAGER ) + 1 ) {
             error_log("GSEC Plugin HU: Row $index has fewer columns than expected. Skipping row.");
             continue;
        }

        $events[] = [
            'start_date' => isset($row[GSEC_COL_START_DATE]) ? trim($row[GSEC_COL_START_DATE]) : '',
            'start_time' => isset($row[GSEC_COL_START_TIME]) ? trim($row[GSEC_COL_START_TIME]) : '',
            'end_date'   => isset($row[GSEC_COL_END_DATE]) ? trim($row[GSEC_COL_END_DATE]) : '',
            'end_time'   => isset($row[GSEC_COL_END_TIME]) ? trim($row[GSEC_COL_END_TIME]) : '',
            'title_hu'   => isset($row[GSEC_COL_TITLE_HU]) ? trim($row[GSEC_COL_TITLE_HU]) : '',
            'title_en'   => isset($row[GSEC_COL_TITLE_EN]) ? trim($row[GSEC_COL_TITLE_EN]) : '',
            'location'   => isset($row[GSEC_COL_LOCATION]) ? trim($row[GSEC_COL_LOCATION]) : '',
            'manager'    => isset($row[GSEC_COL_MANAGER]) ? trim($row[GSEC_COL_MANAGER]) : '',
        ];
    }

    if ( empty( $events ) ) {
         return new WP_Error( 'no_valid_rows', __( 'Nem található érvényes adatsor a CSV feldolgozása után.', 'google-sheet-event-calendar-hu' ) );
    }

    return $events;
}


/**
 * Filters events to include only upcoming ones and sorts them chronologically.
 * Handles specific Hungarian date format 'Y.m.d.'.
 *
 * @param array $events Array of parsed event data.
 * @return array Sorted array of upcoming events.
 */
function gsec_filter_and_sort_events_hu( $events ) {
    $upcoming = [];
    // Ensure WordPress timezone is used for 'now' comparison
    $now = new DateTime('now', new DateTimeZone(wp_timezone_string()));
    $now_timestamp = $now->getTimestamp();

    foreach ( $events as $event ) {
        $start_timestamp = false;
        $start_date_str_raw = $event['start_date'];
        $start_time_str = $event['start_time'];

        // --- Date Parsing ---
        // Trim potential trailing dot and whitespace
        $start_date_str = trim( trim( $start_date_str_raw ), '.' );

        if ( ! empty( $start_date_str ) ) {
            $dt_start = false;
            // Prioritize the specific format Y.m.d
            $dt_start = DateTime::createFromFormat('Y.m.d', $start_date_str, new DateTimeZone(wp_timezone_string()));

            if ($dt_start === false) {
                 // Fallback to other common formats if needed
                 $formats = ['Y-m-d', 'm/d/Y']; // Add others if necessary
                 foreach ($formats as $format) {
                     $dt_start = DateTime::createFromFormat($format, $start_date_str, new DateTimeZone(wp_timezone_string()));
                     if ($dt_start !== false) break;
                 }
            }
            // Last resort: strtotime - less reliable for specific formats but good fallback
             if ($dt_start === false) {
                $parsed_time = strtotime($start_date_str);
                if($parsed_time !== false){
                     $dt_start = new DateTime('@' . $parsed_time);
                     $dt_start->setTimezone(new DateTimeZone(wp_timezone_string())); // Ensure correct timezone
                }
             }

            // --- Time Parsing ---
            if ($dt_start !== false) {
                 // Set time if provided and valid (H:i format)
                 if (!empty($start_time_str) && preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $start_time_str)) {
                    list($hour, $minute) = explode(':', $start_time_str);
                    $dt_start->setTime(intval($hour), intval($minute), 0);
                 } else {
                     // If no valid time, assume start of the day
                     $dt_start->setTime(0, 0, 0);
                 }
                 $start_timestamp = $dt_start->getTimestamp();

            } else {
                 error_log("GSEC Plugin HU: Could not parse start date: " . $start_date_str_raw);
                 continue; // Skip event if date is unparseable
            }

        } else {
             error_log("GSEC Plugin HU: Empty start date found for event title: " . $event['title_hu']);
             continue; // Skip event if date is missing
        }

        // Add event if its start time is in the future (or today)
        // Compare timestamp derived from date potentially set to 00:00 if time missing
        if ( $start_timestamp !== false && $start_timestamp >= $now_timestamp ) {
            $event['start_timestamp'] = $start_timestamp;
            // Simple parsing for end time for display - assuming same format
            $event['end_time_display'] = !empty($event['end_time']) ? trim($event['end_time']) : '';
            $upcoming[] = $event;
        }
    }

    // Sort events by start timestamp (ascending)
    usort( $upcoming, function( $a, $b ) {
        return $a['start_timestamp'] <=> $b['start_timestamp'];
    } );

    return $upcoming;
}


/**
 * Generates the HTML output for the event list (Hungarian Layout).
 *
 * @param array $events Array of sorted, filtered events to display.
 * @return string HTML output.
 */
function gsec_generate_html_hu( $events ) {
    ob_start(); // Start output buffering

    // Set locale to Hungarian for date_i18n - Requires Hungarian language pack installed in WP
    // Note: This might conflict if other parts of the site need a different locale temporarily.
    // Consider checking if WP locale is already Hungarian.
    $original_locale = setlocale(LC_TIME, 0); // Get current locale
    $locale_set = setlocale(LC_TIME, 'hu_HU.UTF-8', 'hu_HU', 'hu', 'hungarian'); // Try setting Hungarian locale

    if ($locale_set === false) {
        error_log("GSEC Plugin HU: Failed to set Hungarian locale. Date formatting might not be correct.");
        // Provide fallback month names if locale setting fails and WordPress doesn't handle 'M' correctly
        $hu_month_abbr = ['jan', 'feb', 'márc', 'ápr', 'máj', 'jún', 'júl', 'aug', 'szept', 'okt', 'nov', 'dec'];
    } else {
        $hu_month_abbr = null; // Indicate that date_i18n should work
    }

    echo '<div class="gsec-event-list-hu">'; // Use a new class for the list

    foreach ( $events as $event ) {
        // Use the Hungarian title directly
        $title = $event['title_hu'];

        // Format the date parts using date_i18n
        $month_abbr = '';
        if ($hu_month_abbr) {
            // Fallback if locale setting failed
             $month_num = date('n', $event['start_timestamp']);
             $month_abbr = $hu_month_abbr[$month_num - 1];
        } else {
            // Try standard WordPress localization first - 'M' usually gives 3-letter abbr.
            // Need to verify if WP Hungarian pack provides 'ápr', 'máj' etc. for 'M'.
             $month_abbr = date_i18n( 'M', $event['start_timestamp'] );
             // Remove potential trailing dot added by some locales/formats
             $month_abbr = rtrim($month_abbr, '.');
        }

        $day = date_i18n( 'j', $event['start_timestamp'] ); // Day without leading zero

        // Format the time range
        $time_range = trim($event['start_time']);
        if ( ! empty( $event['end_time_display'] ) ) {
            // Basic check to avoid adding '-' if end_time is same as start_time (or invalid)
            if(trim($event['end_time_display']) != $time_range){
                 $time_range .= ' - ' . esc_html( $event['end_time_display'] );
            }
        }

        ?>
        <div class="gsec-event-hu">
            <div class="gsec-date-col">
                <span class="gsec-month"><?php echo esc_html( $month_abbr ); ?></span>
                <span class="gsec-day"><?php echo esc_html( $day ); ?></span>
            </div>
            <div class="gsec-details-col">
                <?php if ( ! empty( $time_range ) ) : ?>
                <div class="gsec-time-range"><?php echo esc_html( $time_range ); ?></div>
                <?php endif; ?>
                <h3 class="gsec-event-title"><?php echo esc_html( $title ); ?></h3>
                 <?php
                 /* Optional: Add other details if needed in this column
                 if ( ! empty( $event['location'] ) ) : ?>
                    <div class="gsec-event-location">
                        <span class="gsec-value"><?php echo esc_html( $event['location'] ); ?></span>
                    </div>
                <?php endif; */
                 ?>
            </div>
        </div>
        <?php
    } // end foreach event

    echo '</div>'; // end .gsec-event-list-hu

    // Restore original locale if it was changed
    setlocale(LC_TIME, $original_locale);

    return ob_get_clean(); // Return the buffered output
}


/**
 * Enqueues inline styles for the Hungarian layout.
 */
function gsec_enqueue_styles_hu() {
    // Use a different handle for the Hungarian styles
    $style_handle = 'gsec-styles-hu-handle';
    wp_register_style( $style_handle, false );
    wp_enqueue_style( $style_handle );

    // CSS adjusted for the two-column layout based on the screenshot
    $custom_css = "
        .gsec-event-list-hu {
            margin-bottom: 20px;
            padding: 0;
            list-style: none;
            max-width: 600px; /* Optional: constrain width */
        }
        .gsec-event-hu {
            display: flex; /* Enable Flexbox */
            align-items: flex-start; /* Align items to the top */
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee; /* Separator line */
        }
        .gsec-event-hu:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .gsec-date-col {
            flex: 0 0 50px; /* Fixed width for date column (adjust as needed) */
            text-align: center;
            margin-right: 15px; /* Space between date and details */
            color: #555;
        }
        .gsec-month {
            display: block;
            font-size: 0.9em;
            line-height: 1.2;
            text-transform: lowercase; /* Match screenshot */
            font-weight: normal;
        }
        .gsec-day {
            display: block;
            font-size: 1.4em; /* Larger day number */
            font-weight: bold;
            line-height: 1.1;
        }
        .gsec-details-col {
            flex: 1; /* Allow details column to take remaining space */
        }
        .gsec-time-range {
            font-size: 0.9em;
            color: #555;
            margin-bottom: 4px;
            font-weight: bold;
        }
        .gsec-event-title {
            margin: 0; /* Remove default margins */
            padding: 0;
            font-size: 1.3em; /* Adjust title size */
            font-weight: bold;
            line-height: 1.3;
            color: #333;
        }
        /* Optional: Add hover effect if titles should be links (requires adding <a> tag in HTML) */
        /*
        .gsec-event-title a {
             color: #333;
             text-decoration: none;
        }
        .gsec-event-title a:hover {
             color: #000;
             text-decoration: underline;
        }
        */
    ";
    wp_add_inline_style( $style_handle, $custom_css );
}
// Hook the style enqueue function to wp_enqueue_scripts
// No need to add this action again if it was already present from the previous version,
// but ensure it's called for the *new* style function:
remove_action( 'wp_enqueue_scripts', 'gsec_enqueue_styles' ); // Remove old action if it existed
add_action( 'wp_enqueue_scripts', 'gsec_enqueue_styles_hu' ); // Add new action


?>