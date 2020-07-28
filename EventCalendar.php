<?php
/*

 Yet Another Simple Event Calendar
 https://github.com/improper/mediawiki-extensions-yasec

 Outputs a tabular calendar filled with events automatically generated
 from page titles in a certain namespace. Based on the intersection extension.


 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 http://www.gnu.org/copyleft/gpl.html

*/

if ( !defined( 'MEDIAWIKI' ) ) {
    die( 'This is not a valid entry point to MediaWiki.' );
}

// Extension credits that will show up on Special:Version
$wgExtensionCredits['parserhook'][] = array(
    'path'        => __FILE__,
    'name'        => 'EventCalendar',
    'version'     => '0.2.1',
    'description' => 'Yet Another Simple Event Calendar',
    'url'         => 'https://github.com/improper/mediawiki-extensions-yasec',
    'author'      => 'Steffen Beyer and others',
);

// JavaScript and CSS resources
$wgResourceModules['ext.yasec'] = array(
    // JavaScript and CSS styles. To combine multiple files, just list them as an array.
    'scripts' => array(
        'fullcalendar/lib/moment.min.js',
        'fullcalendar/fullcalendar/fullcalendar.min.js',
        'ext.yasec.core.js',
    ),
    'styles' => array(
        'fullcalendar/fullcalendar/fullcalendar.css',
        'ext.yasec.css',
    ),

    // When your module is loaded, these messages will be available through mw.msg().
    // E.g. in JavaScript you can access them with mw.message( 'myextension-hello-world' ).text()
    // 'messages' => array( 'myextension-hello-world', 'myextension-goodbye-world' ),

    // If your scripts need code from other modules, list their identifiers as dependencies
    // and ResourceLoader will make sure they're loaded before you.
    // You don't need to manually list 'mediawiki' or 'jquery', which are always loaded.
    'dependencies' => array( 'jquery.ui.datepicker' ),

    // You need to declare the base path of the file paths in 'scripts' and 'styles'
    'localBasePath' => __DIR__ . '/resources',
    // ... and the base from the browser as well. For extensions this is made easy,
    // you can use the 'remoteExtPath' property to declare it relative to where the wiki
    // has $wgExtensionAssetsPath configured:
    'remoteExtPath' => 'yasec/resources'
);


// Configuration variables (change in LocalSettings.php)

# How long to cache pages using EventCalendar in seconds. Default to 1 day.
# Set to false to use the normal amount of page caching (most efficient),
# set to 0 to disable cache altogether (inefficient, but results will never
# be outdated)
$wgECMaxCacheTime = 60*60*24;   // How long to cache pages in seconds


$wgHooks['ParserFirstCallInit'][] = 'wfEventCalendar';
/**
 * Set up the <EventCalendar> tag.
 *
 * @param $parser Object: instance of Parser
 * @return Boolean: true
 */
function wfEventCalendar( &$parser ) {
    $parser->setHook( 'EventCalendar', 'renderEventCalendar' );
    return true;
}

// The callback function for converting the input text to HTML output
function renderEventCalendar( $input, $args, $mwParser ) {
    // config variables
    global $wgECMaxCacheTime;

    global $wgContLang;
    global $wgECCounter; // instantiation counter
    $wgECCounter += 1;

    $mwParser->getOutput()->addModules( 'ext.yasec' );

    if ( $wgECMaxCacheTime !== false ) {
        $mwParser->getOutput()->updateCacheExpiry( $wgECMaxCacheTime );
    }

    // defaults
    $aspectRatio = 1.6;
    $namespaceIndex = 0;

    $parameters = explode( "\n", $input );

    foreach ( $parameters as $parameter ) {
        $paramField = explode( '=', $parameter, 2 );
        if( count( $paramField ) < 2 ) {
            continue;
        }
        $type = trim( $paramField[0] );
        $arg = trim( $paramField[1] );
        switch ( $type ) {
            case 'aspectratio':
                $aspectRatio = floatval( $arg );
                break;
            case 'namespace':
                $ns = $wgContLang->getNsIndex( $arg );
                if ( $ns != null ) {
                    $namespaceIndex = $ns;
                }
                break;
        } // end main switch()
    } // end foreach()

    // build the SQL query
    $dbr = wfGetDB( DB_REPLICA );
    $tables = array( 'page' );
    $fields = array( 'page_title' );
    $where = array();
    $options = array();

    $where['page_namespace'] = $namespaceIndex;

    $title_pattern = '^[0-9]{4}/[0-9]{2}/[0-9]{2}_[[:alnum:]]';

    if ( ! $dbr instanceof DatabaseSqlite ) {

        if ( $dbr instanceof DatabasePostgres ) {
            $regexp_op = '~';
        } else {
            $regexp_op = 'REGEXP';
        }

        $where[] = "page_title " . $regexp_op . " '" . $title_pattern . "'";
    }

    $options['ORDER BY'] = 'page_title DESC';
    $options['LIMIT'] = 5000; // should limit output volume to about 300 KiB
                              // assuming 60 bytes per entry

    // process the query
    $res = $dbr->select( $tables, $fields, $where, __METHOD__, $options );

    $eventmap = array();
    foreach ( $res as $row ) {

        if ( $dbr instanceof DatabaseSqlite ) {
            if( ! preg_match("@" . $title_pattern . "@", $row->page_title)) {
                continue;  // Ignoring page titles that don't follow the 
                           // pattern of event pages
            } 
        }

        $date = str_replace( '/', '-', substr( $row->page_title, 0, 10 ));
        $title = str_replace( '_', ' ', substr( $row->page_title, 11 ));
        $url = Title::makeTitle( $namespaceIndex, $row->page_title )->getLinkURL();

        if ( !array_key_exists( $title, $eventmap )) {
            $eventmap[$title] = array();
        }

        // minimal interval is one day
        $tempdate = date_create( $date );
        date_add( $tempdate, date_interval_create_from_date_string( '1 day' ));
        $enddate = date_format( $tempdate, 'Y-m-d' );

        // look for events with same name on consecutive days
        $last = array_pop( $eventmap[$title] );
        if ( $last !== NULL ) {
            if ( $last['start'] == $enddate ) {
                // conflate multi-day event
                $enddate = $last['end'];
            } else {
                // no match, keep last event
                $eventmap[$title][] = $last;
            }
        }

        $eventmap[$title][] = array(
            'title' => $title,
            'start' => $date,
            'end' => $enddate,
            'url' => $url,
        );
    }

    // concatenate all events to single list
    $events = array();
    foreach ( $eventmap as $entries ) {
        $events = array_merge( $events, $entries );
    }

    // calendar container and data array
    $output = "<div id=\"eventcalendar-{$wgECCounter}\"></div>\n" .
        "<script>\n" .
        "if ( typeof window.eventCalendarAspectRatio !== 'object' ) { window.eventCalendarAspectRatio = []; }\n" .
        "window.eventCalendarAspectRatio.push( {$aspectRatio} );\n" .
        "if ( typeof window.eventCalendarData !== 'object' ) { window.eventCalendarData = []; }\n" .
        "window.eventCalendarData.push( " . json_encode( $events ) . " );\n" .
        "</script>\n";

    return array( $output, 'markerType' => 'nowiki' );
}
