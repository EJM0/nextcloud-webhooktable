# Changelog

## 1.2.0

- Ist die Zieldatei gesperrt (z. B. in Nextcloud Office geöffnet), wird die Einsendung zwischengespeichert (Antwort 202 „queued“) und automatisch in Eingangsreihenfolge nachgetragen, sobald die Sperre weg ist: per Hintergrundjob oder mit der nächsten Einsendung. Die Einstellungen zeigen, wie viele Einsendungen warten

## 1.1.0

- Wiederholte Gruppen (z. B. mehrere Artikel einer Bestellung) werden untereinander geschrieben: eine Zeile pro Artikel, Name, E-Mail usw. nur in der ersten Zeile einer Bestellung. Neue Einstellung „Wiederholte Gruppen untereinander schreiben“ (standardmäßig an) und optionale Liste der Gruppenfelder

## 1.0.0

- Erste Version: Webhooks mit geheimem Schlüssel, Ausgabe als Excel (.xlsx) oder CSV, Feldzuordnung, gemeinsame Admin-Einstellungen, Testzeile und Statistik
