=== HinSchG Portal (Multi-Mandant) ===

Contributors: Sven Bolte
Author: ChatGPT and PBMod
Author URI: https://github.com/svenbolte/
Plugin URI: https://github.com/svenbolte/hinschg-portal/
Tags: hinweisgeberschutz, whistleblower, compliance, mandanten, portal, datenschutz
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Version: 1.2.3
Requires at least: 6.0
Tested up to: 6.8.3
Requires PHP: 8.2
Stable tag: 1.2.3

Ein Multi-Mandanten-Hinweisgeberschutz-Portal gemäß HinSchG (Hinweisgeberschutzgesetz) zur sicheren, vertraulichen und anonymen Entgegennahme von Hinweisen.

== Beschreibung ==

Das HinSchG Portal (Multi-Mandant) ist ein WordPress-Plugin zur Umsetzung der Anforderungen des Hinweisgeberschutzgesetzes für mehrere Organisationen (Mandanten).

* Mandantenverwaltung (ID, Name, Ort, Kontakt-E-Mail)
* Frontend-Formular für anonyme Hinweise (Shortcode [hinweisportal])
* Upload von Anhängen (optional)
* Automatische E-Mail-Benachrichtigung an den Mandanten
* Temporärer (48 h) Download-Link für Anhänge in der Benachrichtigungs-E-Mail
* Private Speicherung der Hinweise als Beitragstyp „Hinweis“
* DSGVO-konforme Verarbeitung (keine IP-Speicherung, keine Cookies)
* Responsives Frontend-Design

== Installation ==

1. ZIP-Datei im Backend unter Plugins → Installieren → Plugin hochladen auswählen oder in /wp-content/plugins/ entpacken.
2. Aktivieren.
3. Im Admin-Menü „HinSchG Portal“ öffnen.
4. Mandanten anlegen und Shortcode [hinweisportal] verwenden.

```markdown
**Beispiel-Link für Mandanten:** `https://www.domain.de/hinweisportal/?mandant=12345678`


== Nutzung ==

``` **Shortcode:** [hinweisportal] ```

*Erwartet den URL-Parameter `mandant=XXXXXXXX` (8-stellige Nummer).*

**E-Mail-Benachrichtigung:**
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


== Datenschutz ==

* Es werden keine IP-Adressen, Cookies oder Browser-Daten gespeichert.
* Anhänge und Meldungen werden ausschließlich auf dem Server abgelegt.
* Alle Inhalte sind nur im Backend für berechtigte Administratoren sichtbar.
* Automatische Löschung oder Anonymisierung kann manuell ergänzt werden.

== Screenshots ==

1. **Frontend-Formular:** modernes Hinweisformular mit Betreff, Beschreibung, Upload und optionaler Kontaktmöglichkeit.
2. **Admin-Bereich:** Mandantenverwaltung (ID, Name, Ort, Kontakt-E-Mail).
3. **Hinweisübersicht:** private Beiträge mit Metadaten (Mandant, Vorgangs-ID, Upload).

== Changelog ==


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
