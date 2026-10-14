Version 1.9.21: Überflüssigen Kontakt-Klappbereich inklusive Hinweistext vollständig aus dem öffentlichen Meldeformular entfernt. Anonymes Postfach bleibt unverändert.

Version 1.9.20: Adminbereich „Recht / Anleitung“ um Shortcode mit optionaler Mandanten-ID, Bildoption, Prioritätsregel sowie Hinweise zur anonymen Kommunikation und zu Bestandsdaten ergänzt.

Version 1.9.17: Mandantenformular auf Desktop zweispaltig und mobil einspaltig. Hinweisbox nur beim Neuanlegen.
Version 1.9.15: Die Hilfe zur Ansprechpartner-Zuweisung erscheint bei der Neuanlage wieder als sichtbare Hinweisbox statt als Details-Klappbereich.

Version 1.9.14: Die Hilfe zur Ansprechpartner-Zuweisung erscheint beim Öffnen von „Neuen Mandanten anlegen“, nicht in der Übersicht oder beim Bearbeiten.
Version 1.9.13: Die Hilfe zur Ansprechpartner-Zuweisung erscheint ausschließlich nach erfolgreichem Neuanlegen eines Mandanten, nicht in der normalen Übersicht oder beim Bearbeiten.
Version 1.9.12: Die Anleitung zur Ansprechpartner-Zuweisung in der Mandantenübersicht ist standardmäßig eingeklappt und nur bei Bedarf sichtbar.

Version 1.9.10: Mandantenformular wird nur bei Neu anlegen oder Bearbeiten angezeigt; standardmäßig steht die Mandantenliste im Vordergrund.

Version 1.9.9: Mandanten-Stammdaten wieder direkt aus der Liste bearbeiten (mit vorausgefülltem Formular).

=== HinSchG Portal (Multi-Mandant) ===

Contributors: Sven Bolte
Author: ChatGPT and PBMod
Tags: hinweisgeberschutz, whistleblower, compliance, mandanten, portal, datenschutz
License: Commercial License (includes third-party Open Source components; see license.txt)
Version: 1.9.21
Requires at least: 6.0
Tested up to: 6.8.3
Requires PHP: 8.2

Ein Multi-Mandanten-Hinweisgeberschutz-Portal gemäß HinSchG (Hinweisgeberschutzgesetz) zur sicheren, vertraulichen und anonymen Entgegennahme von Hinweisen.

== Beschreibung ==

Das HinSchG Portal (Multi-Mandant) ist ein WordPress-Plugin zur Umsetzung der Anforderungen des Hinweisgeberschutzgesetzes für mehrere Organisationen (Mandanten).

* Mandantenverwaltung (ID, Name, Ort)
* Frontend-Formular für anonyme Hinweise (Shortcode [hinweisportal])
* Upload von Anhängen (optional)
* Automatische E-Mail-Benachrichtigung an den Mandanten
* Temporärer (48 h) Download-Link für Anhänge in der Benachrichtigungs-E-Mail
* Private Speicherung der Hinweise als Beitragstyp „Hinweis“
* DSGVO-konforme Verarbeitung (keine IP-Speicherung, keine Cookies)
* Responsives Frontend-Design
* Anonymes Zwei-Wege-Postfach ohne E-Mail-Adresse
* Kategorien und interne Risikoklassifikation
* Druckoptimierter Compliance-Nachweis / Fallakte mit Audit-Verlauf

== Installation ==

1. ZIP-Datei im Backend unter Plugins → Installieren → Plugin hochladen auswählen oder in /wp-content/plugins/ entpacken.
2. Plugin aktivieren. Dabei wird automatisch die veröffentlichte Seite „Hinweisgeberschutz Portal“ mit dem Slug `hinweis` und dem Inhalt `[hinweisportal]` angelegt, sofern sie noch nicht vorhanden ist. Eine vorhandene Seite bleibt unverändert.
3. Im Admin-Menü „HinSchG Portal“ öffnen.
4. Mandanten anlegen. Die automatisch angelegte Seite ist unter `/hinweis/` erreichbar.

**Beispiel-Link für Mandanten:** `https://www.domain.de/hinweis/?mandant=12345678`


== Nutzung ==

**Shortcode (Mandanten-ID optional):**

- `[hinweisportal]` – verwendet die 8-stellige Mandanten-ID aus dem URL-Parameter `?mandant=12345678`.
- `[hinweisportal id="12345678"]` – bindet den Mandanten direkt auf einer beliebigen WordPress-Seite ein, ohne URL-Parameter.
- `[hinweisportal id="12345678" image="0"]` – wie oben, aber ohne Titelbild.

Bei gesetzter `id` hat die Shortcode-ID Vorrang vor einem eventuell vorhandenen URL-Parameter. Eine ungültige ID führt zu einer Fehlermeldung statt zur Auswahl eines anderen Mandanten.

**Anonyme Kommunikation und Bestandsdaten:**
* Das Meldeformular erhebt keine Kontakt-E-Mail-Adresse des Hinweisgebers. Rückfragen und Rückmeldungen erfolgen ausschließlich über das geschützte anonyme Postfach mit Vorgangs-ID und Zugang. Zugangsdaten bitte sicher aufbewahren.
* Bereits vor der Umstellung gespeicherte Kontakt-E-Mail-Adressen werden durch das Update nicht automatisch gelöscht. Bestandsdaten und historische Audit-Einträge sind unter Beachtung der Aufbewahrungs- und Löschpflichten gesondert zu prüfen.

**Interne E-Mail-Benachrichtigung:**
* Wird an die in der Mandantenverwaltung hinterlegte Adresse gesendet.
* Enthält den Hinweistext, eine Vorgangs-ID und – falls vorhanden – einen temporären Download-Link zum Anhang (48 h gültig).

**Frontend:**
* Modernes Formular-Design (`.hinschg-form`) mit intuitiver Eingabe.
* Pflichtfelder, Fokus- und Hover-Effekte, mobilfreundlich.


== Haftungsausschluss ==

Hintergrund des Hinweisgeberschutzgesetzes (HinSchG)

Das Hinweisgeberschutzgesetz (HinSchG) dient der Umsetzung der EU-Richtlinie (EU) 2019/1937 über den Schutz von Personen, die Verstöße gegen das Unionsrecht melden (Whistleblower-Richtlinie).
Ziel des Gesetzes ist es, Hinweisgeber – also Personen, die Missstände, Gesetzesverstöße oder unethisches Verhalten in Unternehmen oder Behörden melden – vor Benachteiligungen oder Repressalien zu schützen.
Das Gesetz verpflichtet Unternehmen mit in der Regel mehr als 50 Beschäftigten, interne Meldekanäle einzurichten, über die Mitarbeitende und externe Personen (z. B. Lieferanten) sicher, vertraulich und auf Wunsch anonym Hinweise abgeben können.

Kernpunkte:
Pflicht zur Einrichtung eines vertraulichen Meldewegs.
Schutz der Identität des Hinweisgebers und der betroffenen Personen.
Verpflichtung zur Bearbeitung und Rückmeldung innerhalb gesetzlicher Fristen.
Möglichkeit zur anonymen Hinweisabgabe.
Sanktionen bei unterlassenem Schutz oder Behinderung von Hinweisgebern.
Dein Plugin unterstützt diese gesetzliche Pflicht, indem es eine datenschutzkonforme Plattform zur Entgegennahme solcher Hinweise bereitstellt.

Haftungsausschluss / Disclaimer
Dieses Plugin stellt lediglich ein technisches Werkzeug zur Umsetzung der Anforderungen des Hinweisgeberschutzgesetzes (HinSchG) bereit.
Es ersetzt keine rechtliche Beratung und bietet keine Gewähr für die Vollständigkeit oder rechtliche Konformität der Implementierung im jeweiligen Einzelfall.
Die Einrichtung, rechtliche Bewertung und der datenschutzkonforme Betrieb der Meldeplattform liegen in der alleinigen Verantwortung des Betreibers.
Der Einsatz erfolgt auf eigenes Risiko.
Der Autor übernimmt keine Haftung für unmittelbare oder mittelbare Schäden, die durch die Nutzung oder Nichtnutzung dieses Plugins entstehen, soweit diese nicht auf vorsätzlichem oder grob fahrlässigem Handeln beruhen.
Durch die Nutzung des Plugins erkennen Sie diesen Haftungsausschluss ausdrücklich an.

Das Hinweisgeberschutzportal (Plugin) wird von der GWS mbH ausschließlich als technische Softwarelösung zur Verfügung gestellt.
Der Anbieter erbringt keine Rechtsberatung und übernimmt insbesondere keine Funktion als interne Meldestelle, Ombudsperson, Compliance- oder Ermittlungsstelle. Auch gewährleistet er nicht, dass die Nutzung des Portals allein zur vollständigen Erfüllung gesetzlicher Anforderungen geeignet ist, und dass die im Portal abgebildeten Prozesse den individuellen regulatorischen Anforderungen des Kunden entsprechen. Es obliegt dem Kunden, die rechtliche Zulässigkeit und Angemessenheit seiner Prozesse, Maßnahmen und Entscheidungen eigenverantwortlich zu prüfen und gegebenenfalls qualifizierte Rechtsberatung einzuholen.

Die GWS behält sich vor, Funktionen, technische Spezifikationen, Sicherheitsmaßnahmen und Inhalte des Portals jederzeit angemessen weiterzuentwickeln, anzupassen oder zu ändern, soweit dadurch keine wesentlichen vertraglichen Hauptleistungspflichten beeinträchtigt werden.
Gesetzesänderungen, behördliche Anforderungen oder technische Entwicklungen können Anpassungen des Portals erforderlich machen.

Der Anbieter haftet nur bei Vorsatz, grober Fahrlässigkeit sowie bei Verletzung von Leben, Körper oder Gesundheit. Bei leicht fahrlässiger Verletzung wesentlicher Vertragspflichten („Kardinalpflichten“) ist die Haftung auf den vertragstypischen, vorhersehbaren Schaden begrenzt. Im Übrigen ist die Haftung ausgeschlossen.


== Datenschutz ==

* Es werden keine IP-Adressen, Cookies oder Browser-Daten gespeichert.
* Anhänge und Meldungen werden ausschließlich auf dem Server abgelegt.
* Alle Inhalte sind nur im Backend für berechtigte Administratoren sichtbar.
* Automatische Löschung oder Anonymisierung kann manuell ergänzt werden.

== Screenshots ==

1. **Frontend-Formular:** modernes Hinweisformular mit Betreff, Beschreibung, Upload und optionaler Kontaktmöglichkeit.
2. **Admin-Bereich:** Mandantenverwaltung (ID, Name, Ort).
3. **Hinweisübersicht:** private Beiträge mit Metadaten (Mandant, Vorgangs-ID, Upload).

== Changelog ==

= 1.5.0 =
* Neu: Anonymes Zwei-Wege-Postfach zwischen Hinweisgeber und Meldestelle.
* Neu: Kategorien im Meldeformular und nachträgliche Klassifikation im Backend.
* Neu: Interne Risikoklassifikation (ungeprüft, niedrig, mittel, hoch, kritisch).
* Neu: Audit-Verlauf für Status-, Kategorie-, Risiko- und Postfach-Ereignisse.
* Neu: Druckoptimierte Compliance-Fallakte mit Browser-Druck / PDF-Speichern.
* Neu: E-Mail-Hinweis an den Mandanten bei neuer anonymer Postfach-Nachricht; Nachrichteninhalt bleibt im Portal.


= 1.2.2 =
Secure Token for 7-day Attachment access for tenant recipient

= 1.2.0 =
* Fix: `$dl_token`-Variable wird initialisiert, kein PHP-Warning mehr.
* Neu: Responsives Frontend-Styling für `.hinschg-form`.
* Neu: Temporärer (48 h) Download-Link für Anhänge in E-Mail.
* Stabilität und Sicherheit verbessert.

= 1.1.0 =
* Hinzufügen des Mandanten-Frontends mit Einleitungstext.
* Admin-Menü restrukturiert („Hinweise“ unter „HinSchG Portal“).

= 1.0.0 =
* Erste stabile Version mit Mandanten-Verwaltung und anonymem Hinweisformular.


== Lizenz ==

Dieses Plugin ist freie Software: du kannst es unter den Bedingungen der **GNU General Public License v2 oder später** weitergeben und/oder verändern.

Copyright (C) 2025  
Sven Bolte

== Sicherheitsstand / bekannte Einschraenkungen ==
Benachrichtigungen enthalten keine Meldungstexte, Zugangstokens oder Dateilinks.
Neue Dateianhaenge (PDF, PNG, JPG, TXT; maximal 10 MB) werden ohne Einrichtung in einer eigenen Datenbanktabelle als BLOB gespeichert. Sie sind nicht Teil der Mediathek. Downloads erfordern eine berechtigte Anmeldung und werden pro Fall geprueft. Vorhandene alte Mediathek-Anhaenge werden dadurch nicht automatisch migriert oder abgesichert.
Eine vollstaendige Mandantenisolation, Fristenautomatik und rechtssichere Loeschautomatik sind noch nicht umgesetzt.
Die Software ist ohne weitere technische, organisatorische und rechtliche Pruefung nicht produktionsreif.

== Mandantenberechtigungen (Sicherheitsupdate) ==
Unter HinSchG Portal > Berechtigungen ordnet ein WordPress-Administrator jedem Benutzer explizit achtstellige Mandanten-IDs zu. Ohne Zuweisung werden Fallakten im Backend ausgeblendet und direkte Lese-/Bearbeitungs-/Loeschzugriffe verweigert. Bestehende Zuweisungen werden beim Speichern ersetzt. Das bisher mandantenuebergreifende Cockpit ist vorlaeufig deaktiviert.
WICHTIG: WordPress-Administratoren und Server-/Datenbankbetreiber haben weiterhin technisch privilegierten Zugriff und muessen organisatorisch streng beschraenkt werden. Keine abgeschlossene Sicherheitszertifizierung. Vor Produktivbetrieb REST, Exporte, Backups, Plugins, Multisite und alle Zugriffspfade separat testen.


== Fristen und Aufbewahrung (1.6.0) ==
Unter Hinweise > Fristen und Aufbewahrung werden 7-Tage-Eingangsbestätigung, dreimonatige Rückmeldung und Löschprüfung drei Jahre nach Abschluss angezeigt. Eine tatsächlich erfolgte Eingangsbestätigung kann manuell dokumentiert werden. Die Anzeige ist eine Arbeitshilfe, kein automatischer Versand oder rechtlicher Nachweis. Keine automatische Löschung: rechtliche Aufbewahrungsausnahmen, Dateien, Backups und Mandantenzugriff müssen vor produktivem Einsatz separat geprüft werden.


== Version 1.7.0: Fristenbenachrichtigungen ==

Eine taegliche WP-Cron-Pruefung zaehlt ueberfaellige Eingangsbestatigungen, Rueckmeldungen und Faelle zur Aufbewahrungspruefung. Eine generische Benachrichtigung ohne Falldaten wird hoechstens einmal pro 24 Stunden an die WordPress-Administrator-E-Mail gesendet. Im Admin-Bereich erscheint ein Hinweis mit Link zur Fristenuebersicht. Es erfolgt KEINE automatische Loeschung.

Wichtig: WP-Cron laeuft nur bei Seitenaufrufen; fuer verlaessliche Fristenkontrolle einen echten Server-Cronjob einrichten. Die Administratoradresse muss organisatorisch geeignet und abgesichert sein. Die Fristenberechnung und die datenschutzrechtliche Loeschung muessen vor Produktivbetrieb fachlich validiert werden.


== Version 1.8.0: Kontrollierter Loeschworkflow ==
Unter Fristen und Aufbewahrung koennen berechtigte Administratoren eine Aufbewahrungssperre setzen, nach Fristablauf die Loeschung vormerken und durch einen zweiten, anderen berechtigten Administrator freigeben lassen. Dateianhaenge blockieren die Loeschung bis zur separaten Pruefung. Es findet keine automatische Loeschung statt. Ein minimales Loeschprotokoll wird als WordPress-Option gefuehrt. Die Behandlung von Backups, externen Kopien, Sonderaufbewahrungspflichten und der tatsaechlichen organisatorischen Freigabe bleibt Aufgabe des Betreibers. Vor produktivem Einsatz sind Integrationstests, Berechtigungspruefung und ein Datenschutz-/Loeschkonzept erforderlich.

== Version 1.8.1 ==
- Loeschfreigabe bleibt einfach: zwei verschiedene berechtigte Administratoren.
- Loeschung wird auch bei per Metadatum verknuepften Alt-Anhaengen blockiert.
- Erfolgreiche Loeschung wird erst nach bestaetigtem Entfernen der Fallakte protokolliert.
- Dateianhaenge und Backups bleiben gesondert zu behandeln; keine Produktivfreigabe ohne Tests.

== Version 1.8.2: Einfacher Loeschnachweis ==
Die bestehende Seite Fristen und Aufbewahrung zeigt jetzt die letzten 20 erfolgreich abgeschlossenen Loeschungen mit Zeitpunkt, beteiligten Benutzer-IDs und gekuerzter pseudonymer Pruefsumme. Keine neuen Menues oder Rollen. Das Protokoll enthaelt keine Fallinhalte oder Mandanten-IDs. Es ist nicht manipulationssicher und ersetzt kein externes revisionssicheres Audit-Log. Backups und Anhaenge bleiben gesondert zu behandeln.

= 1.8.3 =
* Fristenübersicht zeigt überfällige Eingangsbestätigungen und Rückmeldungen zuerst und nennt ihre Anzahl. Keine zusätzlichen Einstellungen.

== Version 1.8.4 ==
Fehlerbehebung: WordPress-Administratoren sehen wieder alle Hinweise aller Mandanten und koennen sie bearbeiten. Mandantenzuweisungen beschraenken weiterhin andere Benutzer.

== Version 1.8.5 ==
Einfache Ansprechpartner-Einrichtung und korrigierte Anleitung im Backend.

== Ansprechpartner einrichten (3 Schritte) ==
1. WordPress > Benutzer > Neu hinzufuegen: Benutzer mit Rolle "HinSchG-Ansprechpartner" anlegen.
2. HinSchG Portal > Berechtigungen: Benutzer auswaehlen und achtstellige Mandanten-ID(s) eingeben.
3. Zuweisen klicken. Ansprechpartner sehen ihre Faelle unter Hinweise (im Backend).

WordPress-Administratoren haben grundsaetzlich Zugriff auf alle Faelle aller Mandanten, auch ohne Mandantenzuweisung. Ansprechpartner haben keine Administratorrechte und duerfen nur zugewiesene Faelle sehen und bearbeiten. Die Zuweisung ersetzt vorherige Zuordnungen. Vor produktivem Einsatz Berechtigungen mit zwei Testmandanten pruefen.

== Version 1.8.8 ==
* Cockpit fuer WordPress-Administratoren wieder aktiviert: die vorzeitige Ausgabe mit return entfernt.
* Ansprechpartner erhalten weiterhin keinen Zugriff auf mandantenuebergreifende Cockpit-Statistiken.

== Backend fuer Ansprechpartner (ab 1.8.8) ==
Nach Anmeldung werden HinSchG-Ansprechpartner zur eigenen Vorgangsliste geleitet. Im Backend wird nur der Menuepunkt HinSchG Hinweise angezeigt. Das eigene Profil bleibt per direkter URL erreichbar. Fremde Backend-Bereiche sind gesperrt. Administratoren mit manage_options behalten alle Menues, das Cockpit und Zugriff auf alle Mandanten. Die Mandantenzuordnung erfolgt weiterhin unter HinSchG Portal > Berechtigungen.

Version 1.8.8: Ansprechpartner nutzen ausschliesslich eigene HinSchG-Fallrechte (keine allgemeinen Beitragsrechte). Administratoren behalten Zugriff auf alle Faelle. Speichern von Fallmetadaten fuer berechtigte Ansprechpartner korrigiert. Bitte mit Testkonten pruefen.

= 1.8.9 =
* Behebt Administrator-Berechtigungsfehler nach der Umstellung auf eigene Hinweis-Capabilities. Administratoren behalten uneingeschraenkten Zugriff auf alle HinSchG-Faelle. Ansprechpartner bleiben mandantengebunden.

== Version 1.9.0: Einfache Mandantenzuordnung ==
Mandanten werden direkt in WordPress > Benutzer > Neu hinzufuegen bzw. beim Bearbeiten eines Benutzers per Checkbox ausgewaehlt.
1. Benutzer anlegen und Rolle HinSchG-Ansprechpartner waehlen.
2. Im selben Formular einen oder mehrere Mandanten ankreuzen.
3. Benutzer speichern. Fertig.
Bestehende Zuordnungen bleiben beim Update erhalten und sind im Benutzerprofil vorausgewaehlt.
Die bisherige separate Seite HinSchG Portal > Berechtigungen entfaellt.
WordPress-Administratoren mit manage_options behalten unabhaengig von der Auswahl Vollzugriff auf alle Mandanten und Vorgaenge.
Ohne Mandantenzuordnung sieht ein Ansprechpartner keine Faelle.
Hinweis: Vor Produktiveinsatz Mandantentrennung mit getrennten Testkonten pruefen.

== Version 1.9.1: Hinweis in Mandantenverwaltung ==
Nach dem Anlegen eines Mandanten zeigt die Mandantenverwaltung den nächsten Schritt: Unter WordPress → Benutzer einen HinSchG-Ansprechpartner anlegen oder bearbeiten und im Benutzerprofil einen oder mehrere Mandanten auswählen. WordPress-Administratoren benötigen keine Mandantenzuordnung.

== Version 1.9.2: Ansprechpartner-Rolle vorauswaehlen ==
Der Link "Benutzer anlegen" in der Mandantenverwaltung oeffnet die WordPress-Benutzeranlage mit vorausgewaehlter Rolle "HinSchG-Ansprechpartner". Bei normaler Benutzeranlage bleibt die WordPress-Standardrolle unveraendert. Die Rolle kann vor dem Speichern manuell geaendert werden.

== Version 1.9.3 ==
WooCommerce-Kompatibilitaet: HinSchG-Ansprechpartner duerfen trotz WooCommerce-Backend-Sperre ihre eingeschraenkte Vorgangsverwaltung unter /wp-admin/edit.php?post_type=hinweis aufrufen. Anmeldung ueber WordPress und WooCommerce leitet diese Rolle zur Vorgangsliste. Andere Shop-Kunden bleiben unveraendert. Administratoren behalten Vollzugriff. Bitte nach dem Update mit einem Testkonto pruefen.

== Version 1.9.5 ==
HinSchG-Ansprechpartner erhalten nach der Anmeldung ein einfaches Dashboard mit Kennzahlen ausschliesslich fuer ihre zugewiesenen Mandanten und einem Link zur Vorgangsliste. WordPress-Administratoren behalten das vollstaendige Cockpit.

== Version 1.9.5 ==
Ansprechpartner nutzen jetzt das vollstaendige Cockpit. Alle Kennzahlen, Diagramme, Mandantentabellen und letzten Meldungen werden auf ihre zugewiesenen Mandanten begrenzt. WordPress-Administratoren sehen weiterhin alle Mandanten.

Version 1.9.7: Private Dateianhaenge in der Datenbank, ohne Konfiguration. Neue Uploads werden auf Typ und Groesse geprueft. Alte Medienanhaenge bleiben unveraendert und muessen gesondert geprueft werden. Vor Livebetrieb sind Restore-, Berechtigungs- und Lasttests erforderlich; Backups enthalten die sensiblen BLOB-Daten.

Version 1.9.7: Upload-Typpruefung fuer PDF/PNG/JPG/TXT verbessert; bei Formularfehlern bleiben Betreff, Kategorie und Beschreibung erhalten. Dateianhaenge muessen erneut ausgewaehlt werden.

== Version 1.9.8 ==
Der Compliance-Nachweis dokumentiert Datenbankanhänge (Dateiname, Typ, Größe). Neue Vorgänge protokollieren im Audit-Verlauf, ob ein Anhang eingereicht wurde. Ältere Vorgänge werden aus gespeicherten Daten dargestellt; nicht mehr vorhandene Angaben lassen sich nicht rekonstruieren.

= 1.9.18 =
* Fristenübersicht unter dem tatsächlichen HinSchG-Portal-Administrationsmenü registriert; Hinweislink korrigiert.

== Update-Hinweis 1.9.19 ==

Die freiwillige Kontaktadresse des Hinweisgebers wurde aus Formular, Verarbeitung, Administration und Nachweis entfernt. Rückfragen laufen ausschließlich über das anonyme Postfach. Bereits in früheren Versionen gespeicherte Kontaktadressen werden durch das Update nicht automatisch gelöscht; sie sind bei Bedarf nach Prüfung der Aufbewahrungspflichten gesondert aus den WordPress-Postmetadaten (`_hinschg_contact_email`) zu bereinigen. Historische Audit-Einträge bleiben erhalten.
