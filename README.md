# EXT:h5p — Interaktive Inhalte in TYPO3

H5P bringt interaktive Lerninhalte ins Web: Quizze, Videos mit Fragen, Hotspot-Bilder,
Präsentationen. Diese Extension bindet den offiziellen H5P-Kern in TYPO3 ein — mit
Backend-Modul zum Anlegen und Verwalten, Frontend-Plugin zur Ausgabe sowie Import
und Export von `.h5p`-Paketen.

> **Hinweis.** Die [README im Verzeichnis `patches`](https://github.com/eckonator/t3ext-h5p/tree/v13/patches)
> gehört dazu. Sie erklärt, welche Patches die H5P-Bibliotheken für PHP 8.2
> benötigen und wie sie eingespielt werden.

- Über H5P: <https://h5p.org/>
- Verfügbare Inhaltstypen: <https://h5p.org/content-types-and-applications>

> **Fork-Hinweis.** Basis ist `michielroos/h5p` (GPL-2.0+, Michiel Roos). Das
> Upstream-Projekt wurde vom Autor zur Übernahme freigegeben und nicht weiter
> gepflegt. Diese Fassung ist lokal in `packages/h5p` erheblich weiterentwickelt
> worden; Details siehe [UMSETZUNGSPLAN.md](UMSETZUNGSPLAN.md) und
> [Abweichungen vom Upstream](#abweichungen-vom-upstream).

---

## Inhalt

- [Voraussetzungen und Installation](#voraussetzungen-und-installation)
- [Konfiguration](#konfiguration)
- [Backend-Modul](#backend-modul)
- [Ausgabe im Frontend](#ausgabe-im-frontend)
- [Import und Export](#import-und-export)
- [Kommandozeile](#kommandozeile)
- [Rechte](#rechte)
- [Aufbau](#aufbau)
- [Wartung](#wartung)
- [Bekannte Grenzen](#bekannte-grenzen)
- [Fehlersuche](#fehlersuche)

---

## Voraussetzungen und Installation

| | |
|---|---|
| TYPO3 | 13.4 |
| PHP | 8.2+ mit `ZipArchive` und `mbstring` |
| Pakete | `h5p/h5p-core ^1.27`, `h5p/h5p-editor ^1.25`, `guzzlehttp/guzzle ^7.4` |

Die Extension liegt als lokales Paket unter `packages/h5p` und wird über ein
`path`-Repository eingebunden. Änderungen daran liegen damit im Hauptrepo und
überleben ein `composer update`.

Nach der Installation:

```bash
./bin/typo3 database:updateschema "*.add,*.change"
./bin/typo3 cache:flush
```

Der Dateispeicher erwartet einen Ordner `h5p/` im Standard-FAL-Storage
(üblicherweise `fileadmin/h5p/`). Die Unterordner `content`, `libraries`,
`exports`, `editor`, `cachedassets` und `packages` legt die Extension beim ersten
Zugriff selbst an.

---

## Konfiguration

Admin-Werkzeuge → Einstellungen → Extension Configuration → **h5p**

| Einstellung | Standard | Bedeutung |
|---|---|---|
| `onlyAllowRecordsInSysfolders` | `0` | `1` beschränkt H5P-Datensätze auf Ordner-Seiten |
| `enableExport` | `0` | Erzeugt beim **Speichern** eines Inhalts eine `.h5p`-Datei für den Reuse-Knopf |
| `displayOptionDownload` | `3` | Sichtbarkeit des Reuse-/Download-Knopfes |
| `displayOptionEmbed` | `3` | Sichtbarkeit des Einbetten-Knopfes |

Die beiden Anzeigeoptionen folgen `H5PDisplayOptionBehaviour`:

| Wert | Bedeutung |
|---:|---|
| `0` | nie anzeigen |
| `1` | vom Autor steuerbar, Standard **an** |
| `2` | vom Autor steuerbar, Standard **aus** |
| `3` | immer anzeigen |

Nur bei `1` und `2` wirken die Checkboxen am einzelnen Inhalt. Bei `0` und `3`
entscheidet die globale Einstellung, und das Bearbeiten-Formular blendet die
Checkboxen aus — statt eine wirkungslose Schaltfläche anzubieten.

### Zum Export-Schalter

`enableExport` kostet bei **jedem Speichern** Zeit und Plattenplatz: rund 0,3
Sekunden und je nach Inhalt einige hundert KB bis mehrere MB. Bei 289 Inhalten
sind das grob 10 GB unter `fileadmin/h5p/exports/`. Deshalb standardmäßig aus —
bewusst einschalten, wenn der Reuse-Knopf gebraucht wird, und danach einmalig
[`h5p:generate-exports`](#kommandozeile) laufen lassen.

---

## Backend-Modul

**Web → H5P** (`web_H5pManager`)

| Ansicht | Zweck |
|---|---|
| H5P-Inhalt auf der aktuellen Seite | Inhalte der gewählten Seite |
| Alle H5P-Inhalte | vollständige Liste |
| Neu hinzufügen | Inhalt im Editor anlegen **oder** `.h5p`-Paket hochladen |
| Bibliotheken | installierte Inhaltstypen |

Je Zeile gibt es Anzeigen, Bearbeiten und Löschen. Gelöscht wird **zweistufig**:
Ein Klick führt auf eine Bestätigungsseite, die Titel, Bibliothek und die
einbindenden Content-Elemente auflistet; erst ein Formular-Absenden löscht
wirklich. Das umfasst Datensatz, Dateien, Bibliotheks-Zuordnungen und
Exportdatei — der TYPO3-Papierkorb greift hier **nicht**.

Bibliotheken lassen sich nur löschen, solange sie von keinem Inhalt und keiner
anderen Bibliothek benutzt werden. Die Prüfung steht im Controller, ein direkt
aufgerufener Link kann also nichts beschädigen.

---

## Ausgabe im Frontend

Vier Plugins sind registriert:

| Plugin | Art | Zweck |
|---|---|---|
| `h5p_view` | Inhaltselement | gibt einen H5P-Inhalt aus |
| `h5p_statistics` | Inhaltselement | Ergebnisse des angemeldeten Nutzers |
| `h5p_embedded` | Plugin, Seitentyp `723442` | rahmenlose Ausgabe für `<iframe>` |
| `h5p_ajax` | Plugin | Endpunkte für Ergebnisse und Nutzerdaten |

Unter dem Inhalt erscheint die H5P-Aktionsleiste mit **Reuse**, **Rights of use**
und **Embed** — abhängig von den Anzeigeoptionen. Bei `embedType = iframe` liegt
sie innerhalb des H5P-iframes; im äußeren DOM ist sie deshalb nicht zu finden.

### Einbetten

Der Embed-Knopf liefert einen `<iframe>`-Schnipsel auf den Seitentyp `723442`.
Dieser Seitentyp wird über `ExtensionManagementUtility::addTypoScriptSetup()` in
`ext_localconf.php` registriert und ist damit ohne weiteres Zutun verfügbar — es
muss **kein** statisches TypoScript-Template eingebunden werden.

Die Adresse hat derzeit die Form

```
https://example.org/<seite>?tx_h5p_embedded[contentId]=182&type=723442
```

Funktional, aber nicht hübsch. Für sprechende Adressen wie `/h5p/embed/182`
bräuchte es zusätzlich einen Route Enhancer.

---

## Import und Export

### Export

Mit aktivem `enableExport` entsteht beim Speichern eines Inhalts
`fileadmin/h5p/exports/<slug>-<id>.h5p`. Der Reuse-Knopf im Frontend verlinkt
genau darauf. Für Bestandsinhalte, die seit dem Einschalten nicht gespeichert
wurden, fehlt die Datei — dafür gibt es `h5p:generate-exports`.

### Import

Unter **Neu hinzufügen** oben auf **„Aus .h5p-Datei hochladen"** umschalten, die
Datei wählen und auf **Create** klicken. Der Import läuft über die Kern-Klassen:
`H5PValidator::isValidPackage()` prüft Struktur und Dateitypen,
`H5PStorage::savePackage()` legt Bibliotheken und Inhalt an. Anschließend werden
die Abhängigkeiten aufgebaut, damit der Inhalt im Frontend seine Bibliotheken lädt.

Im Bearbeiten-Formular gibt es denselben Umschalter als **„Durch .h5p-Datei
ersetzen"**. Dort bleibt die Inhalts-ID erhalten, eingebundene Content-Elemente
zeigen also weiterhin auf denselben Inhalt.

> **Ein Paket muss seine Bibliotheken enthalten.** Ein `.h5p` ohne
> Bibliotheksordner wird von der Prüfung abgelehnt. Das betrifft auch eigene
> Exporte von Inhalten, für die keine Abhängigkeiten hinterlegt sind — siehe
> [Bekannte Grenzen](#bekannte-grenzen).

> **Sicherheitshinweis.** Ein `.h5p`-Paket enthält ausführbares JavaScript und
> installiert bei Bedarf neue Bibliotheken. Enthält ein Paket Inhaltstypen, die
> noch fehlen, wird der Import für Nicht-Administratoren mit einer Meldung
> abgebrochen statt halb ausgeführt — siehe [Rechte](#rechte).

---

## Kommandozeile

### `h5p:generate-exports`

Erzeugt fehlende `.h5p`-Dateien für vorhandene Inhalte.

```bash
./bin/typo3 h5p:generate-exports              # nur fehlende ergänzen
./bin/typo3 h5p:generate-exports --alle       # auch vorhandene neu erzeugen
./bin/typo3 h5p:generate-exports --limit=20   # zum Antesten
```

Der vollständige Lauf dauert bei ~290 Inhalten einige Minuten und belegt
zweistellige GB. Erst mit `--limit` antesten und die Größe prüfen.

### `h5p:find-duplicates`

Listet mehrfach vorhandene Inhalte mit uid, Slug, Datum und der Anzahl
einbindender Content-Elemente. **Nur lesend** — welche Fassung die richtige ist,
kann nur die Redaktion entscheiden.

```bash
./bin/typo3 h5p:find-duplicates
./bin/typo3 h5p:find-duplicates --nur-ungenutzt
```

Hintergrund: Bis zur Behebung einer Namenskollision beim Formularfeld `action`
legte jedes Speichern aus der Bearbeiten-Maske eine **Kopie** an, statt den Inhalt
zu ändern. Dieses Kommando macht sichtbar, was sich dabei angesammelt hat.

### `h5p:cleanup-orphaned-files`

Entfernt Ordner unter `fileadmin/h5p`, zu denen es keinen Datensatz mehr gibt.

```bash
./bin/typo3 h5p:cleanup-orphaned-files              # nur auflisten (Standard)
./bin/typo3 h5p:cleanup-orphaned-files --force      # tatsächlich löschen
./bin/typo3 h5p:cleanup-orphaned-files --force --include-deleted
```

**Ohne `--force` wird nichts verändert.** Ordner zu weich gelöschten Datensätzen
werden übersprungen — solche Datensätze lassen sich aus dem Papierkorb
wiederherstellen, ihre Dateien danach nicht mehr. Nicht-numerische Ordnernamen
werden nie gelöscht.

---

## Rechte

`Framework::hasPermission()` bildet H5Ps Rechte auf TYPO3 ab:

| Recht | Wer darf |
|---|---|
| `UPDATE_LIBRARIES`, `INSTALL_RECOMMENDED`, `CREATE_RESTRICTED` | nur Administratoren |
| `DOWNLOAD_H5P`, `EMBED_H5P`, `COPY_H5P` | alle |

Die erste Gruppe installiert oder aktualisiert Bibliotheken, also fremden
JavaScript-Code — das bleibt Administratoren vorbehalten. Die zweite Gruppe
steuert nur, ob eine Schaltfläche am Inhalt erscheint; das entscheidet die
Redaktion pro Inhalt.

Zugang zum Modul regelt wie üblich die Backend-Gruppe über `groupMods`
(`web_H5pManager`) und `tables_modify`.

---

## Aufbau

Die Extension übersetzt zwischen dem H5P-Kern und TYPO3. Der Kern erwartet
Schnittstellen-Implementierungen, die in `Classes/Adapter/` liegen:

| Klasse | Rolle |
|---|---|
| `Adapter/Core/Framework` | `H5PFrameworkInterface` — Datenbank, Optionen, Rechte, Meldungen |
| `Adapter/Core/FileStorage` | `H5PFileStorage` — Dateien über **FAL**, nicht über rohe Pfade |
| `Adapter/Core/CoreFactory` | erweitert `H5PCore`, liest den Export-Schalter |
| `Adapter/Editor/EditorAjax` | Bibliotheken und Übersetzungen für den Editor |
| `Adapter/Editor/EditorStorage` | Dateien des Editors |

Domänenmodelle und Repositories unter `Classes/Domain/` bilden die Tabellen ab:

```
tx_h5p_domain_model_content              Inhalte
tx_h5p_domain_model_contentdependency    Inhalt -> Bibliothek
tx_h5p_domain_model_contentresult        Ergebnisse
tx_h5p_domain_model_library              installierte Bibliotheken
tx_h5p_domain_model_librarydependency    Bibliothek -> Bibliothek
tx_h5p_domain_model_librarytranslation   Übersetzungen der Editor-Oberfläche
tx_h5p_domain_model_contenttypecacheentry  Inhaltstyp-Katalog des Hubs
tx_h5p_domain_model_configsetting         H5P-Optionen
tx_h5p_domain_model_cachedasset           siehe Bekannte Grenzen
```

Dazu kommt in `tt_content` das Feld `tx_h5p_content`, über das ein
Inhaltselement auf einen H5P-Inhalt zeigt.

---

## Wartung

### Zwei Kopien des H5P-Kerns

Das ist die wichtigste Stolperfalle dieser Extension:

| Ort | Inhalt |
|---|---|
| `vendor/h5p/h5p-core/` | der **PHP-Code**, den Composer verwaltet |
| `packages/h5p/Resources/Public/Lib/h5p-core/` | die **Frontend-Assets** (JS, CSS, Schriften) |

Ein `composer update` aktualisiert nur die erste Hälfte. Bringt eine neue
Core-Version zusätzliche Stylesheets oder Skripte mit, müssen sie **von Hand**
nach `Resources/Public/Lib/h5p-core/` kopiert werden — sonst fordert das Frontend
Dateien an, die es nicht gibt.

Verwirrend dabei: Die Asset-Kopie enthält auch eine `h5p.classes.php`. Geladen
wird aber die aus `vendor/`. Wer eine Fehlermeldung mit Zeilennummer verfolgt,
sollte also in `vendor/h5p/h5p-core/` nachsehen, nicht in der Kopie unter
`Resources/`.

Abgleich nach einem Core-Update:

```php
// Listet, was H5PCore erwartet und was die Asset-Kopie hat
foreach (array_merge(H5PCore::$styles, H5PCore::$scripts) as $f) {
    printf("%-40s vendor:%s  assets:%s\n", $f,
        is_file('vendor/h5p/h5p-core/' . $f) ? 'ok' : 'FEHLT',
        is_file('packages/h5p/Resources/Public/Lib/h5p-core/' . $f) ? 'ok' : 'FEHLT');
}
```

Die von den Stylesheets referenzierten Schriften unter `fonts/` nicht vergessen.

### Nach Änderungen an Aktionen des Backend-Moduls

Eine neue Controller-Aktion braucht **zwei** Einträge: die Methode **und** die
Registrierung in `Configuration/Backend/Modules.php` unter `controllerActions`.
Fehlt der zweite, erzeugt `f:uri.action()` eine **leere** URL — die Schaltfläche
erscheint, führt aber nirgendwohin.

### Reservierte Argumentnamen

Formularfelder dürfen nicht `action` oder `controller` heißen: Extbase entscheidet
anhand dieser Argumente, welche Aktion läuft — und zwar vor dem URL-Pfad. Genau
das hat hier über Jahre dafür gesorgt, dass „Update" Kopien anlegte.

---

## Bekannte Grenzen

**Asset-Aggregation ist nicht funktionsfähig.** `FileStorage::cacheAssets()` ist
aus einem Neos/Flow-Port übernommen (`FLOW_PATH_WEB`, `resourceManager`) und
liefe unter TYPO3 nicht. Entsprechend ist `H5PCore::$aggregateAssets` aus, die
Tabelle `cachedasset` leer, und `deleteCachedAssets()` gibt bewusst ein leeres
Array zurück. Für eine gezielte Auswahl fehlt ohnehin die Relation: `CachedAsset`
deklariert ein `ObjectStorage $libraries`, wozu es weder MM-Tabelle noch Spalte
gibt.

**Content Hub.** Die Methoden `replaceContentHubMetadataCache()`,
`getContentHubMetadataCache()` und Verwandte sind Platzhalter. Die Endpunkte
`CONTENT_HUB_METADATA_CACHE` und `GET_HUB_CONTENT` sind im `EditorController`
nicht geroutet.

**Nutzerfortschritt.** Es gibt keine `content_user_data`-Tabelle;
`resetContentUserData()` ist ein dokumentierter No-Op. `contentresult` speichert
abgeschlossene Ergebnisse, nicht den Zwischenstand.

**Exporte ohne Bibliotheken.** `h5p:generate-exports` liest die Abhängigkeiten
aus `tx_h5p_domain_model_contentdependency`. Inhalte ohne Einträge dort ergeben
ein Archiv mit `h5p.json` und `content.json`, aber **ohne Bibliotheksordner** —
wenige KB groß und beim Import nicht gültig. Betroffen sind aktuell 10 von 289
Inhalten. Erkennbar an der Dateigröße: Alles unter ~5 KB ist verdächtig.

**Bewusst nicht implementiert**, weil die installierte Core-Version sie nie
aufruft: `getNumNotFiltered()`, `getLibraryUsage()`, `getAdminUrl()`. Ebenso
`lockDependencyStorage()`/`unlockDependencyStorage()` — auf den meisten
Plattformen legitime No-Ops — sowie `Event::save()`/`saveStats()`, wofür die
Tabelle fehlt.

---

## Fehlersuche

**Der Reuse-Knopf führt ins Leere.** Die Exportdatei entsteht nur beim Speichern.
`enableExport` prüfen und `h5p:generate-exports` laufen lassen.

**Die Checkboxen „Allow users to download" und „Display Embed button" bleiben
leer.** Das ist kein Speicherfehler: Stehen `displayOptionDownload` bzw.
`displayOptionEmbed` auf `3` (immer) oder `0` (nie), ignoriert H5P die Checkboxen
und die Extension blendet sie aus. Auf `1` oder `2` stellen, damit sie wirken.

**Der Upload einer `.h5p`-Datei bricht ab.** Fehlen dem Paket Bibliotheken, darf
sie nur ein Administrator installieren. Die Meldung sagt das; anderenfalls die
Fehlermeldungen des Validators beachten, sie nennen die beanstandete Datei.

**Eine Aktion im Backend-Modul tut nichts.** Prüfen, ob sie in
`Configuration/Backend/Modules.php` registriert ist (siehe [Wartung](#wartung)).

**Frontend meldet fehlende CSS-Dateien.** Die Asset-Kopie hinkt der Core-Version
hinterher — siehe [Zwei Kopien des H5P-Kerns](#zwei-kopien-des-h5p-kerns).

**Stubs melden sich nicht.** `Utility\MaintenanceUtility::methodMissing()` wirft
**nur** im Development-Kontext. Auf Production geben nicht implementierte
Methoden still `null` zurück. Wer Lücken sucht, setzt den Kontext testweise auf
`Development`.

---

## Abweichungen vom Upstream

Diese Fassung weicht erheblich von `michielroos/h5p` ab. Unter anderem wurden
der `.h5p`-Upload repariert, Export, Import, Löschweg und Embed-Ausgabe überhaupt
erst funktionsfähig gemacht, die Rechteprüfung angebunden und drei CLI-Kommandos
ergänzt. Die vollständige Aufstellung mit Begründungen, Fundstellen und Tests
steht in **[UMSETZUNGSPLAN.md](UMSETZUNGSPLAN.md)**.

Vor Arbeiten an dieser Extension lohnt ein Blick dorthin — insbesondere in die
Abschnitte zu den Fallstricken.

---

## Lizenz und Herkunft

GPL-2.0-or-later. Ursprüngliche Extension von **Michiel Roos**
(<https://www.michielroos.com>). H5P selbst ist ein Projekt der H5P Group,
siehe <https://h5p.org/>.
