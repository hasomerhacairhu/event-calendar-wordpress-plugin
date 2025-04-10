<?php
/**
 * Plugin Name:       Google Sheet Event Calendar
 * Plugin URI:        https://github.com/hasomerhacairhu/event-calendar-wordpress-plugin
 * Description:       Displays upcoming events from a Google Sheet CSV using a shortcode. Mimics The Events Calendar list style.
 * Version:           1.0.1
 * Author:            Bedő Marci
 * Author URI:        https://somer.hu/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       google-sheet-event-calendar
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
define('GSEC_COL_TITLE_EN', 5);        // F English title of activity
define('GSEC_COL_LOCATION', 6);        // G Tervezett helyszín (Location)
define('GSEC_COL_MANAGER', 7);         // H Felelős (Manager)

/**
 * Registers the shortcode [google_sheet_event_calendar].
 */
function gsec_register_shortcode() {
    add_shortcode( 'google_sheet_event_calendar', 'gsec_render_shortcode' );
}
add_action( 'init', 'gsec_register_shortcode' );

/**
 * Renders the event calendar shortcode output.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output for the event list.
 */
function gsec_render_shortcode( $atts ) {
    // Define default attributes and merge with user attributes
    $atts = shortcode_atts(
        array(
            'count'   => 5,            // Default number of events to show
            'csv_url' => '',           // Default empty CSV URL
        ),
        $atts,
        'google_sheet_event_calendar'
    );

    // Sanitize attributes
    $event_count = max(1, intval( $atts['count'] )); // Ensure count is at least 1
    $csv_url     = esc_url_raw( trim( $atts['csv_url'] ) ); // Clean the URL

    // Validate CSV URL
    if ( empty( $csv_url ) || ! filter_var( $csv_url, FILTER_VALIDATE_URL ) ) {
        return '<p style="color: red;">' . esc_html__( 'Error: Please provide a valid public Google Sheet CSV URL in the shortcode.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // --- Caching Logic ---
    $cache_key      = 'gsec_event_data_' . md5( $csv_url );
    $cached_events  = get_transient( $cache_key );
    $fetched_events = false; // Initialize as false

    if ( false === $cached_events ) {
        // Cache is expired or doesn't exist, fetch fresh data
        $fetched_events = gsec_fetch_and_parse_csv( $csv_url );

        if ( is_wp_error( $fetched_events ) ) {
            // If fetching failed, return the error message
            return '<p style="color: red;">' . esc_html__( 'Error fetching or parsing CSV:', 'google-sheet-event-calendar' ) . ' ' . esc_html( $fetched_events->get_error_message() ) . '</p>';
        }

        // Store the fetched data in the cache for 6 hours
        set_transient( $cache_key, $fetched_events, 6 * HOUR_IN_SECONDS );

    } else {
        // Use data from cache
        $fetched_events = $cached_events;
    }

    // --- Data Processing ---
    if ( empty( $fetched_events ) ) {
         return '<p>' . esc_html__( 'No event data found in the spreadsheet or cache.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // Filter for upcoming events and sort them
    $upcoming_events = gsec_filter_and_sort_events( $fetched_events );

    // Limit the number of events
    $display_events = array_slice( $upcoming_events, 0, $event_count );

    // --- Rendering ---
    if ( empty( $display_events ) ) {
        return '<p>' . esc_html__( 'No upcoming events found.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // Enqueue styles for the calendar
    gsec_enqueue_styles();

    return gsec_generate_html( $display_events );
}

/**
 * Fetches and parses the CSV data from the given URL.
 *
 * @param string $csv_url The URL of the Google Sheet CSV.
 * @return array|WP_Error Array of event data on success, WP_Error on failure.
 */
function gsec_fetch_and_parse_csv( $csv_url ) {
    $response = wp_remote_get( $csv_url, array( 'timeout' => 15 ) ); // Increased timeout slightly

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'fetch_error', __( 'Could not retrieve data from the URL.', 'google-sheet-event-calendar' ), $response->get_error_message() );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        return new WP_Error( 'fetch_error_code', sprintf( __( 'Received HTTP status code %d when fetching the URL.', 'google-sheet-event-calendar' ), $http_code ) );
    }

    $csv_data = wp_remote_retrieve_body( $response );

    if ( empty( $csv_data ) ) {
         return new WP_Error( 'empty_csv', __( 'The fetched CSV file is empty.', 'google-sheet-event-calendar' ) );
    }

    // Parse CSV data
    $lines = explode( "\n", trim( $csv_data ) );
    $header = null;
    $events = array();

    // Check if there's at least a header and one data row
    if (count($lines) < 2) {
        return new WP_Error( 'no_data_rows', __( 'CSV does not contain any data rows (only header or empty).', 'google-sheet-event-calendar' ) );
    }

    foreach ( $lines as $index => $line ) {
        // Skip empty lines just in case
        if ( empty( trim($line) ) ) continue;

        $row = str_getcsv( trim( $line ) );

        if ( $index === 0 ) {
            // You might want to validate header columns here if needed
            $header = $row;
            continue; // Skip header row
        }

        // Basic check for expected number of columns (adjust if structure is flexible)
        if ( count( $row ) < max( GSEC_COL_START_DATE, GSEC_COL_START_TIME, GSEC_COL_END_DATE, GSEC_COL_END_TIME, GSEC_COL_TITLE_HU, GSEC_COL_TITLE_EN, GSEC_COL_LOCATION, GSEC_COL_MANAGER ) + 1 ) {
            // Log this potentially problematic row, but try to continue if possible
             error_log("GSEC Plugin: Row $index has fewer columns than expected. Skipping row or processing partial data.");
             // Decide whether to skip or try to process partial data. Skipping is safer.
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
         return new WP_Error( 'no_valid_rows', __( 'No valid data rows found after parsing the CSV.', 'google-sheet-event-calendar' ) );
    }

    return $events;
}

/**
 * Filters events to include only upcoming ones and sorts them chronologically.
 *
 * @param array $events Array of parsed event data.
 * @return array Sorted array of upcoming events.
 */
function gsec_filter_and_sort_events( $events ) {
    $upcoming = [];
    $now = current_time( 'timestamp' ); // Use WordPress function for current time

    foreach ( $events as $event ) {
        // Attempt to create a DateTime object from the start date and time
        // Be robust: try common Hungarian/ISO formats, handle missing time gracefully
        $start_timestamp = false;
        $start_datetime_str = trim($event['start_date'] . ' ' . $event['start_time']);

        if (!empty($start_datetime_str)) {
            // Try common formats, add more if needed based on your sheet data
            $formats = [
                'Y.m.d. H:i', 'Y.m.d H:i', 'Y-m-d H:i', 'm/d/Y H:i',
                'Y.m.d. G:i', 'Y.m.d G:i', 'Y-m-d G:i', 'm/d/Y G:i', // Handle single digit hour
                'Y.m.d H:i:s', 'Y-m-d H:i:s', 'm/d/Y H:i:s',
                'Y.m.d.', 'Y.m.d', 'Y-m-d', 'm/d/Y' // Date only fallback
            ];
            foreach ($formats as $format) {
                $dt = DateTime::createFromFormat($format, $start_datetime_str);
                // Check if the format matched AND the original string represents the parsed date correctly
                if ($dt !== false && $dt->format($format) === $start_datetime_str) {
                     $start_timestamp = $dt->getTimestamp();
                     break; // Found a valid format
                } elseif($dt !== false && strpos($format, 'H:i') === false && $dt->format($format) === $event['start_date']) {
                    // Handle date-only formats - assume start of day
                    $start_timestamp = $dt->setTime(0, 0, 0)->getTimestamp();
                    break; // Found a valid date-only format
                }
            }
            // Last resort: strtotime (less reliable for non-standard formats)
            if ($start_timestamp === false) {
                 $start_timestamp = strtotime($start_datetime_str);
            }
        }

        if ( $start_timestamp === false ) {
            // Could not parse date/time, skip this event or log an error
            error_log("GSEC Plugin: Could not parse start date/time: " . $event['start_date'] . ' ' . $event['start_time']);
            continue;
        }

        // Add event if its start time is in the future (or very close to now)
        if ( $start_timestamp >= $now ) {
            $event['start_timestamp'] = $start_timestamp; // Add timestamp for sorting
            $upcoming[] = $event;
        }
    }

    // Sort events by start timestamp (ascending)
    usort( $upcoming, function( $a, $b ) {
        return $a['start_timestamp'] <=> $b['start_timestamp']; // spaceship operator (PHP 7+)
        // Fallback for older PHP: return ($a['start_timestamp'] < $b['start_timestamp']) ? -1 : 1;
    } );

    return $upcoming;
}

/**
 * Generates the HTML output for the event list.
 *
 * @param array $events Array of sorted, filtered events to display.
 * @return string HTML output.
 */
function gsec_generate_html( $events ) {
    ob_start(); // Start output buffering

    echo '<div class="gsec-event-list">';

    foreach ( $events as $event ) {
        // Determine which title to use (prefer English if available, fallback to Hungarian)
        $title = !empty($event['title_en']) ? $event['title_en'] : $event['title_hu'];

        // Format the date and time using WordPress's localization functions
        // Adjust format string as needed (see https://wordpress.org/support/article/formatting-date-and-time/)
        $date_format = get_option( 'date_format', 'Y.m.d' ); // Use WP setting or default
        $time_format = get_option( 'time_format', 'H:i' ); // Use WP setting or default

        $start_display = date_i18n( $date_format, $event['start_timestamp'] );
        if ( ! empty( $event['start_time'] ) ) {
             // Only add time if it was present and parsed
             if(strpos($event['start_time'], ':') !== false){ // Basic check if it looks like a time
                $start_display .= ' ' . date_i18n( $time_format, $event['start_timestamp'] );
             } else {
                // Maybe log that time format was unexpected? Or just omit.
             }
        }

        // Optional: Handle end date/time display if needed
        $end_display = '';
        if (!empty($event['end_date'])) {
             // Similar parsing logic as start date could be added here if precise end time display is needed
             // For simplicity now, just display raw end date if present
             $end_display = ' - ' . esc_html($event['end_date']);
             if (!empty($event['end_time'])) {
                 $end_display .= ' ' . esc_html($event['end_time']);
             }
        }

        ?>
        <div class="gsec-event">
            <div class="gsec-event-datetime">
                <span class="gsec-event-start-datetime"><?php echo esc_html( $start_display ); ?></span>
                <?php /* Optional: Display end date/time if needed
                if (!empty($end_display)) {
                    echo '<span class="gsec-event-end-datetime">' . esc_html( $end_display ) . '</span>';
                }
                */ ?>
            </div>
            <h3 class="gsec-event-title"><?php echo esc_html( $title ); ?></h3>
            <?php if ( ! empty( $event['location'] ) ) : ?>
                <div class="gsec-event-location">
                    <span class="gsec-label"><?php esc_html_e( 'Location:', 'google-sheet-event-calendar' ); ?></span>
                    <span class="gsec-value"><?php echo esc_html( $event['location'] ); ?></span>
                </div>
            <?php endif; ?>
            <?php /* Optional: Display Manager
            <?php if ( ! empty( $event['manager'] ) ) : ?>
                <div class="gsec-event-manager">
                     <span class="gsec-label"><?php esc_html_e( 'Manager:', 'google-sheet-event-calendar' ); ?></span>
                     <span class="gsec-value"><?php echo esc_html( $event['manager'] ); ?></span>
                </div>
            <?php endif; ?>
            */ ?>
        </div>
        <?php
    } // end foreach event

    echo '</div>'; // end .gsec-event-list

    return ob_get_clean(); // Return the buffered output
}

/**
 * Enqueues basic inline styles to mimic The Events Calendar list.
 */
function gsec_enqueue_styles() {
    // Register a dummy handle for our inline styles
    wp_register_style( 'gsec-styles-handle', false );
    wp_enqueue_style( 'gsec-styles-handle' );

    // Basic CSS - Adjust these styles to better match The Events Calendar
    $custom_css = "
        .gsec-event-list {
            margin-bottom: 20px;
            list-style: none; /* Remove default list styling if it were a ul */
            padding: 0;
        }
        .gsec-event {
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .gsec-event:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .gsec-event-datetime {
            font-size: 0.9em;
            color: #555;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .gsec-event-title {
            margin-top: 0;
            margin-bottom: 8px;
            font-size: 1.2em;
            /* Add more styling like font-family if needed */
        }
        .gsec-event-location,
        .gsec-event-manager { /* Style for optional manager field */
            font-size: 0.9em;
            color: #333;
            margin-bottom: 4px;
        }
        .gsec-label {
            font-weight: bold;
            margin-right: 5px;
        }
    ";
    wp_add_inline_style( 'gsec-styles-handle', $custom_css );
}
// Hook the style enqueue function to wp_enqueue_scripts
add_action( 'wp_enqueue_scripts', 'gsec_enqueue_styles' );

?>