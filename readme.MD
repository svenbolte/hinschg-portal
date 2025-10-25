# HinSchG Portal (Multi-Mandant)

## 🧩 Beschreibung

Das **HinSchG Portal (Multi-Mandant)** ist ein WordPress-Plugin zur Umsetzung der Anforderungen des Hinweisgeberschutzgesetzes für mehrere Organisationen (Mandanten).

### Funktionen

- Mandantenverwaltung (ID, Name, Ort, Kontakt-E-Mail)
- Frontend-Formular für anonyme Hinweise via Shortcode `[hinweisportal]`
- Upload von Anhängen (optional)
- Automatische E-Mail-Benachrichtigung an den Mandanten
- Temporärer (48 h) Download-Link für Anhänge in der Benachrichtigungs-E-Mail
- Private Speicherung der Hinweise als Beitragstyp **„Hinweis“**
- DSGVO-konforme Verarbeitung (keine IP-Speicherung, keine Cookies)
- Responsives Frontend-Design


## ⚙️ Installation

1. ZIP-Datei im Backend unter **Plugins → Installieren → Plugin hochladen** auswählen oder in `/wp-content/plugins/` entpacken.  
2. Aktivieren.  
3. Im Admin-Menü **„HinSchG Portal“** öffnen.  
4. Mandanten anlegen und den Shortcode `[hinweisportal]` verwenden.  

**Beispiel-Link für Mandanten:**  
`https://www.domain.de/hinweisportal/?mandant=12345678`


## 💡 Nutzung

### Shortcode

```[hinweisportal]```


> Erwartet den URL-Parameter `mandant=XXXXXXXX` (8-stellige Nummer).

### E-Mail-Benachrichtigung

- Wird an die in der Mandantenverwaltung hinterlegte Adresse gesendet.  
- Enthält den Hinweistext, eine Vorgangs-ID und – falls vorhanden – einen temporären Download-Link zum Anhang (48 h gültig).

### Frontend

- Modernes Formular-Design (`.hinschg-form`) mit intuitiver Eingabe.  
- Pflichtfelder, Fokus- und Hover-Effekte, mobilfreundlich.


## ⚖️ Haftungsausschluss

### Hintergrund des Hinweisgeberschutzgesetzes (HinSchG)

Das Hinweisgeberschutzgesetz (HinSchG) setzt die EU-Richtlinie (EU) 2019/1937 über den Schutz von Personen um, die Verstöße gegen das Unionsrecht melden (**Whistleblower-Richtlinie**).

Ziel ist der Schutz von Hinweisgebern vor Benachteiligungen oder Repressalien.  
Unternehmen mit mehr als 50 Beschäftigten müssen interne Meldekanäle für sichere und vertrauliche Hinweise einrichten.

#### Kernpunkte

- Pflicht zur Einrichtung eines vertraulichen Meldewegs  
- Schutz der Identität des Hinweisgebers und der betroffenen Personen  
- Verpflichtung zur Bearbeitung und Rückmeldung innerhalb gesetzlicher Fristen  
- Möglichkeit zur anonymen Hinweisabgabe  
- Sanktionen bei unterlassenem Schutz oder Behinderung von Hinweisgebern  

> Dieses Plugin unterstützt diese gesetzliche Pflicht, indem es eine datenschutzkonforme Plattform zur Entgegennahme solcher Hinweise bereitstellt.

#### Haftungsausschluss / Disclaimer

Dieses Plugin ist ein technisches Werkzeug zur Umsetzung der Anforderungen des Hinweisgeberschutzgesetzes.  
Es **ersetzt keine rechtliche Beratung** und bietet keine Gewähr für Vollständigkeit oder rechtliche Konformität im Einzelfall.  
Die rechtliche Bewertung und der datenschutzkonforme Betrieb liegen allein in der Verantwortung des Betreibers.  

Der Einsatz erfolgt auf eigenes Risiko.  
Der Autor übernimmt keine Haftung für Schäden, die durch Nutzung oder Nichtnutzung entstehen, soweit diese nicht auf vorsätzlichem oder grob fahrlässigem Handeln beruhen.


## 🔐 Datenschutz

- Keine Speicherung von IP-Adressen, Cookies oder Browser-Daten  
- Anhänge und Meldungen werden ausschließlich auf dem Server abgelegt  
- Alle Inhalte sind nur im Backend für berechtigte Administratoren sichtbar  
- Optionale manuelle Ergänzung zur automatischen Löschung oder Anonymisierung möglich  


## 📄 Lizenz

Dieses Plugin ist freie Software: du kannst es unter den Bedingungen der  
**GNU General Public License v2 oder später** weitergeben und / oder verändern.

Copyright (C) 2025  
**Sven Bolte**
