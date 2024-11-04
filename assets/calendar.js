/**
 * Generates a valid iCal file as a string given the calendar metadata and a list of events.
 * @param {Object} calendarMetaData - Metadata of the calendar:
 *   - name. The name of the calendar.
 *   - timezone: The timezone of the calendar.
 * @param {Object[]} events - A list of events. Each event should have the following properties:
 *   - title: The title of the event.
 *   - start: The start date of the event. Should be a Date object.
 *   - end: The end date of the event. Should be a Date object.
 *   - location: The location of the event.
 *   - description: The description of the event.
 *   - id: The unique id of the event.
 * @returns {String} The generated iCal file as a string.
 */
function generateICSFile(calendarMetaData, events) {
    let icsContent = `BEGIN:VCALENDAR
        VERSION:2.0
        PRODID:-//ical.marudot.com//EN
        CALSCALE:GREGORIAN
        METHOD:PUBLISH
        X-WR-CALNAME:${calendarMetaData.name}
        X-WR-TIMEZONE:${calendarMetaData.timezone || 'Etc/GMT'}
    `;
  
    events.forEach((event) => {
        icsContent += `BEGIN:VEVENT
            SUMMARY:${event.title}
            DTSTART;VALUE=DATE:${event.start.toISOString().slice(0, 10).replace(/-/g, '')}
            DTEND;VALUE=DATE:${event.end.toISOString().slice(0, 10).replace(/-/g, '')}
            LOCATION:${event.location}
            DESCRIPTION:${event.description}
            UID:${event.id}@ical.marudot.com
            DTSTAMP:${new Date().toISOString().replace(/[-:]/g, '')}
            STATUS:CONFIRMED
            TRANSP:TRANSPARENT
            SEQUENCE:0
            END:VEVENT
        `;
    });
  
    icsContent += 'END:VCALENDAR';
    return icsContent;
}