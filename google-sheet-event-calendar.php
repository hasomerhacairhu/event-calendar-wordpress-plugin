<?php
/**
 * Plugin Name:       Google Sheet Event Calendar
 * Plugin URI:        https://github.com/hasomerhacairhu/event-calendar-wordpress-plugin
 * Description:       Displays upcoming events from a Google Sheet CSV using a shortcode. Styled for Hungarian layout, shows location, handles multi-day events, and allows cache duration control.
 * Version:           1.2.1
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
 * Load plugin textdomain for internationalization.
 */
function gsec_load_textdomain() {
    load_plugin_textdomain( 'google-sheet-event-calendar', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'gsec_load_textdomain' );


/**
 * Registers the shortcode [google_sheet_event_calendar].
 */
function gsec_register_shortcode() {
    add_shortcode( 'google_sheet_event_calendar', 'gsec_render_shortcode_hu' );
}
add_action( 'init', 'gsec_register_shortcode' );

/**
 * Renders the event calendar shortcode output (Hungarian Layout).
 * Handles 'count', 'csv_url', and 'cache_hours' parameters.
 *
 * @param array $atts Shortcode attributes.
 * @return string HTML output for the event list.
 */
function gsec_render_shortcode_hu( $atts ) {
    // Define default attributes and merge with user attributes
    $atts = shortcode_atts(
        array(
            'count'       => 5,    // Default number of events to show
            'csv_url'     => '',   // Default empty CSV URL
            'cache_hours' => 6,    // Default cache duration in hours
        ),
        $atts,
        'google_sheet_event_calendar' // The shortcode name
    );

    // Sanitize attributes
    $event_count = max(1, intval( $atts['count'] ));
    $csv_url     = esc_url_raw( trim( $atts['csv_url'] ) );
    $cache_hours = max(0, floatval( $atts['cache_hours'] ));

    // Validate CSV URL
    if ( empty( $csv_url ) || ! filter_var( $csv_url, FILTER_VALIDATE_URL ) ) {
        return '<p style="color: red;">' . esc_html__( 'Hiba: Kérjük, adjon meg egy érvényes Google Táblázat CSV URL-t a shortcode-ban.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // --- Caching Logic ---
    $cache_key      = 'gsec_event_data_hu_' . md5( $csv_url );
    $fetched_events = false;
    $use_cache      = ( $cache_hours > 0 );

    if ( $use_cache ) {
        $cached_events = get_transient( $cache_key );
    } else {
        $cached_events = false;
        // delete_transient( $cache_key ); // Optional: Force clear if switching to no-cache
    }

    if ( false === $cached_events ) {
        $fetched_events = gsec_fetch_and_parse_csv_hu( $csv_url );

        if ( is_wp_error( $fetched_events ) ) {
            return '<p style="color: red;">' . sprintf(
                esc_html__( 'Hiba a CSV lekérésekor vagy feldolgozásakor (%s):', 'google-sheet-event-calendar' ),
                esc_html( $fetched_events->get_error_code() )
            ) . ' ' . esc_html( $fetched_events->get_error_message() ) . '</p>';
        }

        if ( $use_cache && ! is_wp_error( $fetched_events ) ) {
            $expiration = intval( $cache_hours * HOUR_IN_SECONDS );
            set_transient( $cache_key, $fetched_events, $expiration );
        }
    } else {
        $fetched_events = $cached_events;
    }

    // --- Data Processing ---
     if ( empty( $fetched_events ) || is_wp_error( $fetched_events ) ) {
         if ( ! is_wp_error( $fetched_events ) ) {
             return '<p>' . esc_html__( 'Nem található eseményadat a táblázatban vagy a gyorsítótárban.', 'google-sheet-event-calendar' ) . '</p>';
         }
         error_log("GSEC Plugin: Unexpected WP_Error object after cache check for URL: " . $csv_url);
         return '<p style="color: red;">' . esc_html__( 'Hiba történt az eseményadatok feldolgozása közben.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // Filter for upcoming events and sort them (includes multi-day logic)
    $upcoming_events = gsec_filter_and_sort_events_hu( $fetched_events );

    // Limit the number of events
    $display_events = array_slice( $upcoming_events, 0, $event_count );

    // --- Rendering ---
    if ( empty( $display_events ) ) {
        return '<p>' . esc_html__( 'Nincsenek közelgő események.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // Enqueue styles for the calendar
    gsec_enqueue_styles_hu();

    // Generate HTML (includes location and multi-day layout)
    return gsec_generate_html_hu( $display_events );
}


/**
 * Fetches and parses the CSV data from the given URL.
 *
 * @param string $csv_url The URL of the Google Sheet CSV.
 * @return array|WP_Error Array of event data on success, WP_Error on failure.
 */
function gsec_fetch_and_parse_csv_hu( $csv_url ) {
    $response = wp_remote_get( $csv_url, array( 'timeout' => 15 ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'fetch_error', __( 'Nem sikerült lekérni az adatokat az URL-ről.', 'google-sheet-event-calendar' ), $response->get_error_message() );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        return new WP_Error( 'fetch_error_code', sprintf( __( 'A %d HTTP státuszkód érkezett az URL lekérésekor.', 'google-sheet-event-calendar' ), $http_code ) );
    }

    $csv_data = wp_remote_retrieve_body( $response );

    if ( empty( $csv_data ) ) {
         return new WP_Error( 'empty_csv', __( 'A letöltött CSV fájl üres.', 'google-sheet-event-calendar' ) );
    }

    // Parse CSV data
    $lines = explode( "\n", trim( $csv_data ) );
    $header = null;
    $events = array();

    if (count($lines) < 2) {
        return new WP_Error( 'no_data_rows', __( 'A CSV nem tartalmaz adatsorokat (csak fejlécet vagy üres).', 'google-sheet-event-calendar' ) );
    }

    foreach ( $lines as $index => $line ) {
        if ( empty( trim($line) ) ) continue;
        $row = str_getcsv( trim( $line ) );

        if ( $index === 0 ) {
            $header = $row;
            continue;
        }

        $expected_columns = max( GSEC_COL_START_DATE, GSEC_COL_START_TIME, GSEC_COL_END_DATE, GSEC_COL_END_TIME, GSEC_COL_TITLE_HU, GSEC_COL_TITLE_EN, GSEC_COL_LOCATION, GSEC_COL_MANAGER ) + 1;
        if ( count( $row ) < $expected_columns ) {
             $row = array_pad($row, $expected_columns, '');
             error_log("GSEC Plugin: Row $index has fewer than $expected_columns columns. Padded row.");
        }

        // Trim all values just in case
        $row = array_map('trim', $row);

        $events[] = [
            'start_date' => isset($row[GSEC_COL_START_DATE]) ? $row[GSEC_COL_START_DATE] : '',
            'start_time' => isset($row[GSEC_COL_START_TIME]) ? $row[GSEC_COL_START_TIME] : '',
            'end_date'   => isset($row[GSEC_COL_END_DATE]) ? $row[GSEC_COL_END_DATE] : '',
            'end_time'   => isset($row[GSEC_COL_END_TIME]) ? $row[GSEC_COL_END_TIME] : '',
            'title_hu'   => isset($row[GSEC_COL_TITLE_HU]) ? $row[GSEC_COL_TITLE_HU] : '',
            'title_en'   => isset($row[GSEC_COL_TITLE_EN]) ? $row[GSEC_COL_TITLE_EN] : '',
            'location'   => isset($row[GSEC_COL_LOCATION]) ? $row[GSEC_COL_LOCATION] : '',
            'manager'    => isset($row[GSEC_COL_MANAGER]) ? $row[GSEC_COL_MANAGER] : '',
        ];
    }

    if ( empty( $events ) ) {
         return new WP_Error( 'no_valid_rows', __( 'Nem található érvényes adatsor a CSV feldolgozása után.', 'google-sheet-event-calendar' ) );
    }

    return $events;
}


/**
 * Filters events to include only upcoming ones and sorts them chronologically.
 * Handles specific Hungarian date format 'Y.m.d.'.
 * Parses end date and adds 'is_multi_day' flag.
 *
 * @param array $events Array of parsed event data.
 * @return array Sorted array of upcoming events.
 */
function gsec_filter_and_sort_events_hu( $events ) {
    if ( ! is_array( $events ) ) {
        error_log("GSEC Plugin: Invalid data passed to gsec_filter_and_sort_events_hu. Expected array, got: " . gettype($events));
        return [];
    }

    $upcoming = [];
    $site_timezone = new DateTimeZone(wp_timezone_string());
    $now = new DateTime('now', $site_timezone);
    // Get timestamp for the very start of today for comparison
    $today_start_timestamp = (new DateTime('today', $site_timezone))->getTimestamp();

    foreach ( $events as $event ) {
        if ( ! is_array($event) || ! isset($event['start_date']) || ! isset($event['start_time']) || ! isset($event['title_hu']) ) {
            error_log("GSEC Plugin: Skipping invalid event data structure during filtering: " . print_r($event, true));
            continue;
        }

        $start_timestamp = false;
        $dt_start = null;
        $dt_end = null;
        $is_multi_day = false;

        // --- Parse Start Date ---
        $start_date_str_raw = $event['start_date'];
        $start_time_str = $event['start_time'];
        $start_date_str = trim( trim( $start_date_str_raw ), '.' );

        if ( ! empty( $start_date_str ) ) {
            $dt_start = DateTime::createFromFormat('Y.m.d', $start_date_str, $site_timezone);
            if ($dt_start === false) {
                 $formats = ['Y-m-d', 'm/d/Y'];
                 foreach ($formats as $format) {
                     $dt_start = DateTime::createFromFormat($format, $start_date_str, $site_timezone);
                     if ($dt_start !== false) break;
                 }
            }
             if ($dt_start === false) {
                $parsed_time = strtotime($start_date_str);
                if($parsed_time !== false){
                     $dt_start = new DateTime('@' . $parsed_time);
                     $dt_start->setTimezone($site_timezone);
                }
             }

            if ($dt_start !== false) {
                 if (!empty($start_time_str) && preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $start_time_str)) {
                    list($hour, $minute) = explode(':', $start_time_str);
                    $dt_start->setTime(intval($hour), intval($minute), 0);
                 } else {
                     $dt_start->setTime(0, 0, 0);
                 }
                 $start_timestamp = $dt_start->getTimestamp();
            } else {
                 error_log("GSEC Plugin: Could not parse start date: " . $start_date_str_raw);
                 continue;
            }
        } else {
             error_log("GSEC Plugin: Empty start date found for event title: " . $event['title_hu']);
             continue;
        }

        // --- Parse End Date (used for multi-day check and display) ---
        $end_timestamp_for_display = null; // Timestamp for end date display
        $end_date_str_raw = isset($event['end_date']) ? $event['end_date'] : '';
        if (!empty($end_date_str_raw)) {
            $end_date_str = trim( trim( $end_date_str_raw ), '.' );
            if (!empty($end_date_str)) {
                $dt_end = DateTime::createFromFormat('Y.m.d', $end_date_str, $site_timezone);
                 if ($dt_end === false) {
                     $formats = ['Y-m-d', 'm/d/Y'];
                     foreach ($formats as $format) {
                         $dt_end = DateTime::createFromFormat($format, $end_date_str, $site_timezone);
                         if ($dt_end !== false) break;
                     }
                 }
                  if ($dt_end === false) {
                     $parsed_time = strtotime($end_date_str);
                     if($parsed_time !== false){
                          $dt_end = new DateTime('@' . $parsed_time);
                          $dt_end->setTimezone($site_timezone);
                     }
                  }
                  if ($dt_end instanceof DateTime) {
                      // Set time for end date - useful for knowing when the event *actually* ends
                      // Let's assume end time column refers to the end date. Default to end of day? Or start? Let's use start for timestamp consistency.
                      $end_time_str = isset($event['end_time']) ? $event['end_time'] : '';
                      if (!empty($end_time_str) && preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $end_time_str)) {
                         list($hour, $minute) = explode(':', $end_time_str);
                         $dt_end->setTime(intval($hour), intval($minute), 0);
                      } else {
                          $dt_end->setTime(0, 0, 0); // Default to start of end day if time missing/invalid
                      }
                      $end_timestamp_for_display = $dt_end->getTimestamp();
                  }
            }
        }

        // --- Check if event is still relevant (ends today or later) ---
        $event_ends_timestamp = $end_timestamp_for_display ?? $start_timestamp; // Use end timestamp if available, else start timestamp
        // Make sure end time comparison accounts for the whole day if only date is given
        if ($dt_end instanceof DateTime) {
            // If end date is today or later, it's relevant
            if ($dt_end->setTime(23, 59, 59)->getTimestamp() < $today_start_timestamp) {
                 continue; // Skip if the event ended before today
            }
        } else {
             // If no end date, check if start date is today or later
             if ($start_timestamp < $today_start_timestamp) {
                 continue; // Skip if single-day event was yesterday or earlier
             }
        }


        // --- Determine if Multi-Day ---
        if ($dt_start instanceof DateTime && $dt_end instanceof DateTime) {
             if ($dt_start->format('Y-m-d') !== $dt_end->format('Y-m-d')) {
                 $is_multi_day = true;
                 $event['end_timestamp_for_display'] = $end_timestamp_for_display; // Store for display
             }
        }

        // Add processed data to the event array
        $event['start_timestamp'] = $start_timestamp;
        $event['is_multi_day'] = $is_multi_day;
        $event['end_time_display'] = (isset($event['end_time']) && !empty($event['end_time'])) ? trim($event['end_time']) : '';
        $event['location'] = (isset($event['location']) && !empty($event['location'])) ? trim($event['location']) : '';

        $upcoming[] = $event;
    } // End foreach event

    // Sort events by start timestamp (ascending)
    usort( $upcoming, function( $a, $b ) {
         $ts_a = isset($a['start_timestamp']) ? $a['start_timestamp'] : 0;
         $ts_b = isset($b['start_timestamp']) ? $b['start_timestamp'] : 0;
         return $ts_a <=> $ts_b;
    } );

    return $upcoming;
}


/**
 * Generates the HTML output for the event list (Hungarian Layout).
 * Adds location and handles multi-day display.
 *
 * @param array $events Array of sorted, filtered events to display.
 * @return string HTML output.
 */
function gsec_generate_html_hu( $events ) {
    ob_start();

    $original_locale = setlocale(LC_TIME, 0);
    $locale_set = setlocale(LC_TIME, 'hu_HU.UTF-8', 'hu_HU', 'hu', 'hungarian');

    if ($locale_set === false) {
        error_log("GSEC Plugin: Failed to set Hungarian locale. Date formatting might use fallback/default.");
        $hu_month_abbr = ['jan', 'feb', 'márc', 'ápr', 'máj', 'jún', 'júl', 'aug', 'szept', 'okt', 'nov', 'dec'];
    } else {
        $hu_month_abbr = null;
    }

    echo '<div class="gsec-event-list-hu">';

    foreach ( $events as $event ) {
        if (!is_array($event) || !isset($event['title_hu']) || !isset($event['start_timestamp'])) {
            continue;
        }

        $title = $event['title_hu'];
        $location = $event['location'];
        $is_multi_day = $event['is_multi_day'];
        $start_timestamp = $event['start_timestamp'];
        // Use the specific timestamp for end date display
        $end_timestamp = isset($event['end_timestamp_for_display']) ? $event['end_timestamp_for_display'] : null;

        // --- Date Formatting ---
        $start_month_abbr = '';
        if ($hu_month_abbr) {
             $month_num = date('n', $start_timestamp);
             $start_month_abbr = $hu_month_abbr[$month_num - 1];
        } else {
             $start_month_abbr = rtrim(date_i18n( 'M', $start_timestamp ), '.');
        }
        $start_day = date_i18n( 'j', $start_timestamp );

        $end_month_abbr = '';
        $end_day = '';
        // Ensure $end_timestamp is valid before trying to format
        if ($is_multi_day && !is_null($end_timestamp)) {
             if ($hu_month_abbr) {
                 $month_num = date('n', $end_timestamp);
                 $end_month_abbr = $hu_month_abbr[$month_num - 1];
             } else {
                 $end_month_abbr = rtrim(date_i18n( 'M', $end_timestamp ), '.');
             }
             $end_day = date_i18n( 'j', $end_timestamp );
        } else {
             // If not multi-day or end_timestamp is missing, clear multi-day flag for safety
             $is_multi_day = false;
        }


        // --- Time Formatting ---
        $time_range = isset($event['start_time']) ? trim($event['start_time']) : '';
        $end_time_display = isset($event['end_time_display']) ? trim($event['end_time_display']) : '';
        // Only show end time if it's different from start time and not empty
        if ( ! empty( $end_time_display ) && $end_time_display != $time_range) {
                 $time_range .= ' - ' . esc_html( $end_time_display );
        }
        // If only start time is present, just show that. If both missing, show nothing.


        // Add multi-day class if needed
        $event_classes = 'gsec-event-hu';
        if ($is_multi_day) {
            $event_classes .= ' gsec-event-multi-day';
        }

        ?>
        <div class="<?php echo esc_attr($event_classes); ?>">
            <div class="gsec-date-col">
                <?php // Use the corrected $is_multi_day flag here ?>
                <?php if ($is_multi_day): ?>
                    <div class="gsec-date-range">
                        <span class="gsec-date-part gsec-start-date">
                            <span class="gsec-month"><?php echo esc_html( $start_month_abbr ); ?></span>
                            <span class="gsec-day"><?php echo esc_html( $start_day ); ?></span>
                        </span>
                        <span class="gsec-date-separator">-</span>
                         <span class="gsec-date-part gsec-end-date">
                            <span class="gsec-month"><?php echo esc_html( $end_month_abbr ); ?></span>
                            <span class="gsec-day"><?php echo esc_html( $end_day ); ?></span>
                        </span>
                    </div>
                <?php else: // Single day display ?>
                    <span class="gsec-month"><?php echo esc_html( $start_month_abbr ); ?></span>
                    <span class="gsec-day"><?php echo esc_html( $start_day ); ?></span>
                <?php endif; ?>
            </div>
            <div class="gsec-details-col">
                <?php if ( ! empty( $time_range ) ) : ?>
                <div class="gsec-time-range"><?php echo esc_html( $time_range ); ?></div>
                <?php endif; ?>
                <h3 class="gsec-event-title"><?php echo esc_html( $title ); ?></h3>
                <?php if ( ! empty( $location ) ) : ?>
                <div class="gsec-event-location"><?php echo esc_html( $location ); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    } // End foreach

    echo '</div>'; // End gsec-event-list-hu

    setlocale(LC_TIME, $original_locale);

    return ob_get_clean();
}


/**
 * Enqueues inline styles for the Hungarian layout.
 * Includes styles for location and multi-day events.
 */
function gsec_enqueue_styles_hu() {
    $style_handle = 'gsec-styles-hu-handle';
    wp_register_style( $style_handle, false );
    wp_enqueue_style( $style_handle );

    $custom_css = "
        .gsec-event-list-hu {
            margin-bottom: 20px;
            padding: 0;
            list-style: none;
            max-width: 600px; /* Optional: constrain width */
        }
        .gsec-event-hu {
            display: flex;
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
            flex: 0 0 60px; /* Slightly wider perhaps for date range */
            text-align: center;
            margin-right: 15px; /* Space between date and details */
            color: #555;
            padding-top: 2px; /* Align top of date with top of text */
        }
        /* Single Day Date Style */
        .gsec-event-hu:not(.gsec-event-multi-day) .gsec-month {
            display: block;
            font-size: 0.9em;
            line-height: 1.2;
            text-transform: lowercase;
            font-weight: normal;
        }
        .gsec-event-hu:not(.gsec-event-multi-day) .gsec-day {
            display: block;
            font-size: 1.4em; /* Larger day number */
            font-weight: bold;
            line-height: 1.1;
        }

        /* Multi Day Date Styles */
        .gsec-event-multi-day .gsec-date-range {
             font-size: 0.85em; /* Make text slightly smaller for range */
             line-height: 1.3;
        }
         .gsec-event-multi-day .gsec-date-part {
             display: block; /* Stack start and end dates */
         }
         .gsec-event-multi-day .gsec-date-separator {
             display: block; /* Separator on its own line */
             font-size: 0.8em;
             margin: 1px 0;
             /* content: '-'; Optional: Use CSS content for separator */
         }
         .gsec-event-multi-day .gsec-date-part .gsec-month,
         .gsec-event-multi-day .gsec-date-part .gsec-day {
             display: inline; /* Display month and day inline within the part */
             margin: 0 1px; /* Tiny space between month/day */
             font-weight: normal; /* Normal weight for range */
         }
         .gsec-event-multi-day .gsec-date-part .gsec-day {
            font-weight: bold; /* Keep day bold */
         }
         .gsec-event-multi-day .gsec-date-part .gsec-month {
            text-transform: lowercase;
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
            margin-bottom: 4px; /* Add space below title for location */
        }
        /* Location Style */
        .gsec-event-location {
            font-size: 0.85em; /* Smaller font */
            color: #777;      /* Grey color */
            line-height: 1.4;
        }

    ";
    wp_add_inline_style( $style_handle, $custom_css );
}

// === Action Hooks ===
add_action( 'wp_enqueue_scripts', 'gsec_enqueue_styles_hu' );

?>