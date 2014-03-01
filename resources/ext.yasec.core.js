jQuery( document ).ready( function( $ ) {
    if ( typeof window.eventCalendarData === 'object' ) {
        for ( var i = 0; i < window.eventCalendarData.length; i++ ) {
            // render calendar
            $( "#eventcalendar-" + ( i + 1 )).fullCalendar({
                aspectRatio: window.eventCalendarAspectRatio[i],
                events: window.eventCalendarData[i],
                lang: window.eventCalendarLang[i]
            });

            // FIXME sometimes init is called too early, it seems, so rerender to be sure
            ( function( i ) {
                window.setTimeout( function() { 
                    $( "#eventcalendar-" + ( i + 1 )).fullCalendar( 'render' );
                }, 0 );
            })( i );
        }
    }
});
