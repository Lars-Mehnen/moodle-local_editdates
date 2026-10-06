<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for component 'local_editdates', language 'de'.
 *
 * @package    local_editdates
 * @copyright  2026 Lars Mehnen <lars.mehnen@technikum-wien.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Kursdaten-API';

$string['editdates:view'] = 'Kurstermine über die Termin-API lesen';
$string['editdates:edit'] = 'Kurstermine über die Termin-API ändern';

$string['availablefrom'] = 'Zugriff beschränkt ab';
$string['availableuntil'] = 'Zugriff beschränkt bis';

$string['eventdatesupdated'] = 'Kurstermine über die API geändert';

$string['maxupdates'] = 'Maximale Anzahl Termine pro Aufruf';
$string['maxupdates_desc'] = 'Obergrenze für die Anzahl der Termine, die ein API-Aufruf ändern darf. '
    . 'Eine größere Anfrage wird abgelehnt und nicht teilweise ausgeführt.';
$string['maxshiftdays'] = 'Maximale Verschiebung (Tage)';
$string['maxshiftdays_desc'] = 'Obergrenze für den Versatz der Verschiebefunktion in Tagen. '
    . 'Das ist die Absicherung gegen eine falsche Einheit in einer automatisierten Anfrage.';

$string['erroroffsetzero'] = 'Ein Versatz von null Sekunden würde nichts ändern.';
$string['erroroffsettoolarge'] = 'Der angeforderte Versatz von {$a->days} Tagen übersteigt das '
    . 'konfigurierte Maximum von {$a->max} Tagen.';
$string['errortoomanyupdates'] = 'Die Anfrage umfasst {$a->count} Termine, das konfigurierte '
    . 'Maximum ist {$a->max}.';
$string['missingreportplugin'] = 'Der Bericht "Termine" (report_editdates) ist nicht installiert. '
    . 'Diese API verwendet dessen modulspezifische Termindefinitionen und kann ohne ihn nicht arbeiten.';

$string['reason_nopermission'] = 'Sie haben keine Berechtigung, diesen Termin zu ändern.';
$string['reason_generalsection'] = 'Der allgemeine Abschnitt kann keine Zugriffsbeschränkung '
    . 'mit Datum haben.';
$string['reason_availabilitydisabled'] = 'Zugriffsbeschränkungen sind auf dieser Website deaktiviert.';
$string['reason_unreadableavailability'] = 'Die Zugriffsbeschränkungen dieses Elements können '
    . 'nicht gelesen werden.';
$string['reason_nesteddateavailability'] = 'Ein Datum liegt innerhalb einer gruppierten '
    . 'Zugriffsbeschränkung; bitte in der Moodle-Oberfläche bearbeiten, damit die Gruppierung '
    . 'erhalten bleibt.';
$string['reason_notandavailability'] = 'Die Zugriffsbeschränkungen sind mit "oder" verknüpft; '
    . 'die Änderung eines einzelnen Datums würde ihre Bedeutung verändern. Bitte in der '
    . 'Moodle-Oberfläche bearbeiten.';
$string['reason_duplicatedateavailability'] = 'Das Element hat mehr als ein Beschränkungsdatum in '
    . 'derselben Richtung; es ist nicht eindeutig, welches geändert werden soll.';
$string['reason_unknowndate'] = 'Diesen Termin gibt es in diesem Kurs nicht.';
$string['reason_invalidvalue'] = 'Ein Termin muss ein positiver Unix-Zeitstempel sein oder 0, '
    . 'um ihn abzuschalten.';
$string['reason_noteditable'] = 'Dieser Termin kann über die API nicht geändert werden.';
$string['reason_notoptional'] = 'Dieser Termin ist erforderlich und kann nicht abgeschaltet werden.';
$string['reason_enddatebeforestartdate'] = 'Das Kursende würde vor dem Kursbeginn liegen.';
$string['reason_nostartdate'] = 'Der Kursbeginn kann nicht entfernt werden.';
$string['reason_fromafteruntil'] = 'Das "ab"-Datum würde auf oder nach dem "bis"-Datum liegen.';
$string['reason_modulevalidation'] = 'Die Aktivität hat die neuen Termine abgelehnt.';

$string['privacy:metadata'] = 'Das Plugin Kursdaten-API speichert keine personenbezogenen Daten. '
    . 'Es liest und schreibt Termine der Kurskonfiguration; wer was geändert hat, steht im '
    . 'Standard-Logbuch von Moodle.';
