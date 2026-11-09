# Webhook-Tabelle für Nextcloud

Nimmt Formular-Einsendungen (z. B. von **Forminator** in WordPress) per Webhook an und schreibt jede Einsendung als neue Zeile in eine **Excel-Datei (.xlsx)** oder **CSV-Datei** in Nextcloud.

- Beliebig viele Webhooks, jeder mit eigenem geheimen Schlüssel
- Speicherort frei wählbar: beliebiger Benutzer und Ordner, auch geteilte Ordner oder Team-Ordner
- Neue Formularfelder werden automatisch als neue Spalten ergänzt
- Eigene Spalten (z. B. „Bezahlt“, „Ausgegeben“) und Formatierungen in der Tabelle bleiben erhalten, neue Zeilen werden nur unten angehängt
- Optionale Feldzuordnung: `name-1 = Name`
- **Gemeinsame Einstellungen für alle Administratoren** (nicht pro Admin)
- Schutz durch geheime Schlüssel, Bruteforce-Schutz und Ratenbegrenzung von Nextcloud
- Keine externen Abhängigkeiten, kein Composer nötig

Unterstützt Nextcloud 31 bis 35 und PHP 8.1 oder neuer (mit den Erweiterungen `zip` und `dom`).

## Installation

```bash
cd /var/www/nextcloud/apps            # Pfad zu eurem Nextcloud-apps-Ordner
git clone https://github.com/EMJ/nextcloud-webhooktable.git webhooktable
sudo -u www-data php ../occ app:enable webhooktable
```

Der Ordner muss **`webhooktable`** heißen. Die fertig gebauten Dateien in `js/` und `css/` sind im Repository enthalten, ein `npm`-Build ist also nicht nötig.

Alternativ entpackt ihr das Release-Archiv `webhooktable-1.0.0.tar.gz` in den `apps`-Ordner.

## Einrichtung

1. In Nextcloud **Verwaltungseinstellungen → Webhook-Tabelle** öffnen.
2. **Neuen Webhook anlegen** wählen, dann Name, Speicherort-Benutzer, Ordner, Dateiname und Format festlegen.
3. **Testzeile schreiben** drücken. Damit prüft ihr, ob Ordner und Rechte passen.
4. **Adresse mit Schlüssel kopieren** und im Formular-Plugin als Webhook-Adresse eintragen.

### Forminator (WordPress)

Im Formular unter **Integrationen → Webhook** die kopierte Adresse einfügen:

```
https://cloud.example.de/index.php/apps/webhooktable/hook/<id>?token=<schlüssel>
```

Forminator sendet die Felder mit technischen Namen wie `name-1`, `email-1` oder `select-1`. Schickt zuerst eine Testbestellung ab und schaut euch die Spaltenüberschriften in der Tabelle an. Danach könnt ihr in der **Feldzuordnung** lesbare Namen vergeben:

```
name-1 = Name
email-1 = E-Mail
phone-1 = Telefon
select-1 = Größe
number-1 = Anzahl
consent-1 =
```

Eine leere rechte Seite (`consent-1 =`) bedeutet: Dieses Feld wird nicht gespeichert. Mit **„Nur zugeordnete Felder speichern“** landen ausschließlich die aufgeführten Felder in der Tabelle.

## Webhook-Schnittstelle

| | |
|---|---|
| Adresse | `POST /index.php/apps/webhooktable/hook/<id>` |
| Schlüssel | Header `X-Webhook-Token: …`, Header `Authorization: Bearer …` oder Parameter `?token=…` |
| Daten | JSON (`application/json`) oder Formulardaten (`application/x-www-form-urlencoded`, `multipart/form-data`) |
| Antwort | `200 {"status":"ok"}`; `401` bei falschem Schlüssel; `422` mit Fehlermeldung, wenn nicht gespeichert werden konnte |
| Verbindungstest | `GET` auf dieselbe Adresse mit Schlüssel liefert `{"status":"ready"}`, ohne etwas zu schreiben |

Verschachtelte JSON-Daten werden mit Punkt flach gemacht (`adresse.ort`), Listen werden mit Komma verbunden (`Trikot, Hose`).

```bash
curl -X POST "https://cloud.example.de/index.php/apps/webhooktable/hook/<id>" \
  -H "X-Webhook-Token: <schlüssel>" \
  -H "Content-Type: application/json" \
  -d '{"name-1":"Anna Müller","email-1":"anna@example.de","select-1":"M"}'
```

## Gut zu wissen

- **Wer sieht was:** Nur Administratoren sehen die Einstellungsseite, und alle Administratoren sehen und bearbeiten dieselben Webhooks. Die Tabelle selbst liegt beim gewählten Speicherort-Benutzer. Teilt sie mit den Personen, die sie brauchen, oder legt sie in einen Team-Ordner.
- **Datei gleichzeitig geöffnet:** Ist die Tabelle gerade in Collabora oder OnlyOffice geöffnet, während eine Bestellung eingeht, kann das Speichern im Editor die neue Zeile überschreiben. Die Tabelle darum nicht dauerhaft geöffnet lassen oder für die Auswertung eine Kopie verwenden.
- **Excel:** Werte werden als Text gespeichert. Telefonnummern behalten führende Nullen, und Eingaben wie `=…` werden nie als Formel ausgeführt. Bei CSV wird Formeln ein `'` vorangestellt.
- **CSV:** Neue Dateien werden mit Semikolon und UTF-8-BOM angelegt, damit ein deutsches Excel sie direkt korrekt öffnet.
- **Datenschutz:** Die Daten werden direkt von eurer Website an eure Nextcloud geschickt, ohne Drittanbieter. Verwendet unbedingt HTTPS.
- Ein Formularfeld mit dem Namen `token` oder `id` wird nicht gespeichert, weil diese Namen für die Schnittstelle reserviert sind.

## Entwicklung

Basiert auf dem offiziellen [Nextcloud App-Template](https://github.com/nextcloud/app_template).

```bash
npm ci
npm run build    # baut js/ und css/
npm run lint
```

Aufbau:

- `lib/Controller/WebhookController.php`: öffentlicher Webhook (Schlüsselprüfung, Bruteforce-Schutz, Ratenbegrenzung)
- `lib/Controller/SettingsController.php`: Verwaltung, nur für Administratoren
- `lib/Service/EndpointService.php`: gemeinsame Konfiguration in der App-Konfiguration (Schlüssel verschlüsselt gespeichert)
- `lib/Service/XlsxWriter.php` / `CsvWriter.php`: hängen Zeilen an, ohne den Rest der Datei zu verändern
- `src/`: Einstellungsseite (Vue 3, `@nextcloud/vue`)

## Lizenz

AGPL-3.0-or-later
