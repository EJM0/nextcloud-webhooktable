# Übergabe an Claude CLI: Repo nach GitHub pushen

## Ausgangslage

Die Nextcloud-App „Webhook-Tabelle“ (App-ID `webhooktable`) ist fertig, getestet und lokal committet.
Sie wurde in einer Cloud-Sitzung gebaut und als `webhooktable-repo.zip` geliefert. Die ZIP-Datei enthält den Ordner `webhooktable/` **inklusive `.git`** (Branch `main`, 2 Commits).
Es ist **kein Remote** eingerichtet, und es wurde noch nichts gepusht.

Wichtig: Die gebauten Frontend-Dateien in `js/` und `css/` sind absichtlich eingecheckt, damit die App ohne npm-Build installierbar ist. Nicht in `.gitignore` aufnehmen und nicht löschen. Nur `js/*.map` ist ignoriert.

## Aufgabe

1. `webhooktable-repo.zip` entpacken (liegt vermutlich im Download-Ordner) und in den Ordner `webhooktable` wechseln.
2. Prüfen: `git status` muss sauber sein, `git log --oneline` muss die Commits zeigen.
3. Auf GitHub unter dem Account/der Organisation **EMJ** ein neues, leeres Repo `nextcloud-webhooktable` anlegen, ohne README, Lizenz oder .gitignore, weil das alles schon da ist:
   ```bash
   gh repo create EMJ/nextcloud-webhooktable --public --description "Nextcloud-App: Formular-Webhooks (z. B. Forminator) fortlaufend in Excel/CSV-Tabellen schreiben"
   ```
   Falls der Account nicht genau `EMJ` heißt: mit `gh auth status` bzw. `gh org list` den richtigen Namen ermitteln und den Benutzer fragen. Privat oder öffentlich vorher mit dem Benutzer klären.
4. Remote setzen und pushen:
   ```bash
   git remote add origin https://github.com/EMJ/nextcloud-webhooktable.git
   git push -u origin main
   ```
5. Optional: ein Release `v1.0.0` erstellen und das Installationsarchiv anhängen. Das Archiv enthält nur Laufzeitdateien:
   ```bash
   mkdir -p /tmp/rel/webhooktable
   cp -r appinfo lib templates img js css LICENSE README.md CHANGELOG.md /tmp/rel/webhooktable/
   find /tmp/rel/webhooktable/js -name '*.map' -delete
   tar -czf webhooktable-1.0.0.tar.gz -C /tmp/rel webhooktable
   gh release create v1.0.0 webhooktable-1.0.0.tar.gz --title "1.0.0" --notes-file CHANGELOG.md
   ```

## Hinweise

- Commit-Autor ist „EMJ“ mit der E-Mail-Adresse des Benutzers. Lehnt GitHub den Push wegen E-Mail-Datenschutz ab („push declines due to email privacy restrictions“), kann der Autor auf die noreply-Adresse umgestellt werden: `git commit --amend --reset-author --no-edit` (für alle Commits `git rebase -r --root --exec "git commit --amend --reset-author --no-edit"`).
- Die GitHub-Workflows in `.github/workflows` sind reine Lint-Checks und brauchen keine Secrets.
- `bugs`-URL in `appinfo/info.xml` und die Installationsanleitung in `README.md` verweisen auf `https://github.com/EMJ/nextcloud-webhooktable`. Wenn das Repo anders heißt, beide Stellen anpassen und committen.

## Projektüberblick (für spätere Änderungen)

- `lib/Controller/WebhookController.php`: öffentlicher Endpunkt `POST /index.php/apps/webhooktable/hook/{id}`, Schlüssel per `X-Webhook-Token`, `Authorization: Bearer` oder `?token=`
- `lib/Controller/SettingsController.php`: Verwaltung, nur für Admins
- `lib/Service/EndpointService.php`: gemeinsame Konfiguration aller Admins (IAppConfig, Schlüssel als sensitive gespeichert)
- `lib/Service/XlsxWriter.php` / `CsvWriter.php`: Zeilen anhängen ohne Fremdbibliothek, bestehende Formatierungen bleiben erhalten
- `src/*.vue`: Einstellungsseite (Vue 3, `@nextcloud/vue` 9). Nach Änderungen `npm ci && npm run build` ausführen und `js/` und `css/` mitcommitten.
- Unterstützt Nextcloud 31 bis 35, PHP 8.1 oder neuer
