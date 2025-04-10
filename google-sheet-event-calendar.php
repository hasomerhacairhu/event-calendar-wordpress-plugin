<?php
/**
 * Plugin Name:       Google Sheet Event Calendar
 * Plugin URI:        https://github.com/hasomerhacairhu/event-calendar-wordpress-plugin
 * Description:       Displays upcoming events from a Google Sheet CSV using a shortcode. Mimics The Events Calendar list style. Adjusted for Hungarian layout.
 * Version:           1.1.1
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
define('GSEC_COL_TITLE_EN', 5);        // F English title of activity (Kept for data structure, but not used in output)
define('GSEC_COL_LOCATION', 6);        // G Tervezett helyszín (Location) (Kept for data structure, but not used in output)
define('GSEC_COL_MANAGER', 7);         // H Felelős (Manager) (Kept for data structure, but not used in output)

/**
 * Load plugin textdomain for internationalization.
 */
function gsec_load_textdomain() {
    // Use the text domain specified in the header
    load_plugin_textdomain( 'google-sheet-event-calendar', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'gsec_load_textdomain' );


/**
 * Registers the shortcode [google_sheet_event_calendar].
 */
function gsec_register_shortcode() {
    // Use the original shortcode name, but point to the function that renders the HU layout
    add_shortcode( 'google_sheet_event_calendar', 'gsec_render_shortcode_hu' );
}
add_action( 'init', 'gsec_register_shortcode' );

/**
 * Renders the event calendar shortcode output (Hungarian Layout).
 * This function is now triggered by [google_sheet_event_calendar].
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
        'google_sheet_event_calendar' // Use the original shortcode name here for attribute parsing
    );

    // Sanitize attributes
    $event_count = max(1, intval( $atts['count'] ));
    $csv_url     = esc_url_raw( trim( $atts['csv_url'] ) );

    // Validate CSV URL
    if ( empty( $csv_url ) || ! filter_var( $csv_url, FILTER_VALIDATE_URL ) ) {
        // Use the text domain from the header
        return '<p style="color: red;">' . esc_html__( 'Hiba: Kérjük, adjon meg egy érvényes Google Táblázat CSV URL-t a shortcode-ban.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // --- Caching Logic ---
    // Using a distinct cache key including 'hu' to separate from potential older caches
    $cache_key      = 'gsec_event_data_hu_' . md5( $csv_url );
    $cached_events  = get_transient( $cache_key );
    $fetched_events = false;

    if ( false === $cached_events ) {
        // Cache is expired or doesn't exist, fetch fresh data
        $fetched_events = gsec_fetch_and_parse_csv_hu( $csv_url );

        if ( is_wp_error( $fetched_events ) ) {
            return '<p style="color: red;">' . sprintf(
                // Use the text domain from the header
                esc_html__( 'Hiba a CSV lekérésekor vagy feldolgozásakor (%s):', 'google-sheet-event-calendar' ),
                esc_html( $fetched_events->get_error_code() )
            ) . ' ' . esc_html( $fetched_events->get_error_message() ) . '</p>';
        }

        set_transient( $cache_key, $fetched_events, 6 * HOUR_IN_SECONDS );

    } else {
        // Use data from cache
        $fetched_events = $cached_events;
    }

    // --- Data Processing ---
    if ( empty( $fetched_events ) ) {
         // Use the text domain from the header
         return '<p>' . esc_html__( 'Nem található eseményadat a táblázatban vagy a gyorsítótárban.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // Filter for upcoming events and sort them
    $upcoming_events = gsec_filter_and_sort_events_hu( $fetched_events );

    // Limit the number of events
    $display_events = array_slice( $upcoming_events, 0, $event_count );

    // --- Rendering ---
    if ( empty( $display_events ) ) {
        // Use the text domain from the header
        return '<p>' . esc_html__( 'Nincsenek közelgő események.', 'google-sheet-event-calendar' ) . '</p>';
    }

    // Enqueue styles for the calendar
    gsec_enqueue_styles_hu();

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
        // Use the text domain from the header
        return new WP_Error( 'fetch_error', __( 'Nem sikerült lekérni az adatokat az URL-ről.', 'google-sheet-event-calendar' ), $response->get_error_message() );
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        // Use the text domain from the header
        return new WP_Error( 'fetch_error_code', sprintf( __( 'A %d HTTP státuszkód érkezett az URL lekérésekor.', 'google-sheet-event-calendar' ), $http_code ) );
    }

    $csv_data = wp_remote_retrieve_body( $response );

    if ( empty( $csv_data ) ) {
         // Use the text domain from the header
         return new WP_Error( 'empty_csv', __( 'A letöltött CSV fájl üres.', 'google-sheet-event-calendar' ) );
    }

    // Parse CSV data
    $lines = explode( "\n", trim( $csv_data ) );
    $header = null;
    $events = array();

    if (count($lines) < 2) {
        // Use the text domain from the header
        return new WP_Error( 'no_data_rows', __( 'A CSV nem tartalmaz adatsorokat (csak fejlécet vagy üres).', 'google-sheet-event-calendar' ) );
    }

    foreach ( $lines as $index => $line ) {
        if ( empty( trim($line) ) ) continue;
        $row = str_getcsv( trim( $line ) );

        if ( $index === 0 ) {
            $header = $row;
            continue;
        }

        // Increased robustness for column count check
        $expected_columns = max( GSEC_COL_START_DATE, GSEC_COL_START_TIME, GSEC_COL_END_DATE, GSEC_COL_END_TIME, GSEC_COL_TITLE_HU, GSEC_COL_TITLE_EN, GSEC_COL_LOCATION, GSEC_COL_MANAGER ) + 1;
        if ( count( $row ) < $expected_columns ) {
             // Pad the row with empty strings if columns are missing, to avoid undefined index errors
             $row = array_pad($row, $expected_columns, '');
             error_log("GSEC Plugin: Row $index has fewer than $expected_columns columns. Padded row.");
             // Alternatively, skip the row:
             // error_log("GSEC Plugin: Row $index has fewer than $expected_columns columns. Skipping row.");
             // continue;
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
         // Use the text domain from the header
         return new WP_Error( 'no_valid_rows', __( 'Nem található érvényes adatsor a CSV feldolgozása után.', 'google-sheet-event-calendar' ) );
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
    $now = new DateTime('now', new DateTimeZone(wp_timezone_string()));
    $now_timestamp = $now->getTimestamp();

    foreach ( $events as $event ) {
        $start_timestamp = false;
        $start_date_str_raw = $event['start_date'];
        $start_time_str = $event['start_time'];

        $start_date_str = trim( trim( $start_date_str_raw ), '.' );

        if ( ! empty( $start_date_str ) ) {
            $dt_start = false;
            $dt_start = DateTime::createFromFormat('Y.m.d', $start_date_str, new DateTimeZone(wp_timezone_string()));

            if ($dt_start === false) {
                 $formats = ['Y-m-d', 'm/d/Y'];
                 foreach ($formats as $format) {
                     $dt_start = DateTime::createFromFormat($format, $start_date_str, new DateTimeZone(wp_timezone_string()));
                     if ($dt_start !== false) break;
                 }
            }
             if ($dt_start === false) {
                $parsed_time = strtotime($start_date_str);
                if($parsed_time !== false){
                     $dt_start = new DateTime('@' . $parsed_time);
                     $dt_start->setTimezone(new DateTimeZone(wp_timezone_string()));
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

        if ( $start_timestamp !== false && $start_timestamp >= $now_timestamp ) {
            $event['start_timestamp'] = $start_timestamp;
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
        $title = $event['title_hu'];
        $month_abbr = '';

        if ($hu_month_abbr) {
             $month_num = date('n', $event['start_timestamp']);
             $month_abbr = $hu_month_abbr[$month_num - 1];
        } else {
             $month_abbr = date_i18n( 'M', $event['start_timestamp'] );
             $month_abbr = rtrim($month_abbr, '.');
        }

        $day = date_i18n( 'j', $event['start_timestamp'] );

        $time_range = trim($event['start_time']);
        if ( ! empty( $event['end_time_display'] ) ) {
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
            </div>
        </div>
        <?php
    }

    echo '</div>';

    setlocale(LC_TIME, $original_locale);

    return ob_get_clean();
}


/**
 * Enqueues inline styles for the Hungarian layout.
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
            align-items: flex-start;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .gsec-event-hu:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        .gsec-date-col {
            flex: 0 0 50px;
            text-align: center;
            margin-right: 15px;
            color: #555;
        }
        .gsec-month {
            display: block;
            font-size: 0.9em;
            line-height: 1.2;
            text-transform: lowercase;
            font-weight: normal;
        }
        .gsec-day {
            display: block;
            font-size: 1.4em;
            font-weight: bold;
            line-height: 1.1;
        }
        .gsec-details-col {
            flex: 1;
        }
        .gsec-time-range {
            font-size: 0.9em;
            color: #555;
            margin-bottom: 4px;
            font-weight: bold;
        }
        .gsec-event-title {
            margin: 0;
            padding: 0;
            font-size: 1.3em;
            font-weight: bold;
            line-height: 1.3;
            color: #333;
        }
    ";
    wp_add_inline_style( $style_handle, $custom_css );
}
// Hook the style enqueue function to wp_enqueue_scripts
add_action( 'wp_enqueue_scripts', 'gsec_enqueue_styles_hu' );

?>