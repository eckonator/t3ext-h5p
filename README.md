# EXT:h5p — Interactive content in TYPO3

H5P brings interactive learning material to the web: quizzes, videos with
embedded questions, hotspot images, presentations. This extension wires the
official H5P core into TYPO3 — with a backend module for authoring and managing
content, a frontend plugin for output, and import and export of `.h5p` packages.

> **Note.** The [README in the `patches` directory](https://github.com/eckonator/t3ext-h5p/tree/v13/patches)
> belongs with this one. It explains which patches the H5P libraries need for
> PHP 8.2 compatibility, and how to apply them.

- About H5P: <https://h5p.org/>
- Available content types: <https://h5p.org/content-types-and-applications>

> **Fork notice.** This is based on `michielroos/h5p` (GPL-2.0+, Michiel Roos).
> The upstream project was released for adoption by its author and is no longer
> maintained. This version has been developed considerably further; see
> [Differences from upstream](#differences-from-upstream) for details.

---

## Contents

- [Requirements and installation](#requirements-and-installation)
- [Configuration](#configuration)
- [Backend module](#backend-module)
- [Frontend output](#frontend-output)
- [Import and export](#import-and-export)
- [Command line](#command-line)
- [Permissions](#permissions)
- [Architecture](#architecture)
- [Maintenance](#maintenance)
- [Known limitations](#known-limitations)
- [Troubleshooting](#troubleshooting)

---

## Requirements and installation

| | |
|---|---|
| TYPO3 | 13.4 |
| PHP | 8.2+ with `ZipArchive` and `mbstring` |
| Packages | `h5p/h5p-core ^1.27`, `h5p/h5p-editor ^1.25`, `guzzlehttp/guzzle ^7.4` |

The extension is installed as a Composer package (`michielroos/h5p`). Since it is
not on Packagist, your project needs its own entry under `repositories` — either
a `vcs` entry pointing at this repository, or a `path` entry if you want the
extension to live inside the project itself.

After installation:

```bash
./bin/typo3 database:updateschema "*.add,*.change"
./bin/typo3 cache:flush
```

File storage expects an `h5p/` folder in the default FAL storage (usually
`fileadmin/h5p/`). The subfolders `content`, `libraries`, `exports`, `editor`,
`cachedassets` and `packages` are created by the extension on first access.

---

## Configuration

Admin Tools → Settings → Extension Configuration → **h5p**

| Setting | Default | Meaning |
|---|---|---|
| `onlyAllowRecordsInSysfolders` | `0` | `1` restricts H5P records to folder pages |
| `enableExport` | `0` | Creates a `.h5p` file for the reuse button whenever content is **saved** |
| `displayOptionDownload` | `3` | Visibility of the reuse/download button |
| `displayOptionEmbed` | `3` | Visibility of the embed button |

Both display options follow `H5PDisplayOptionBehaviour`:

| Value | Meaning |
|---:|---|
| `0` | never show |
| `1` | author's choice, default **on** |
| `2` | author's choice, default **off** |
| `3` | always show |

The per-content checkboxes only take effect for `1` and `2`. With `0` and `3`
the global setting decides, and the edit form hides the checkboxes rather than
offering a control that does nothing.

### About the export switch

`enableExport` costs time and disk space on **every save**: roughly 0.3 seconds
and, depending on the content, anywhere from a few hundred KB to several MB. With
a few hundred content items that quickly adds up to double-digit GB under
`fileadmin/h5p/exports/`. Hence it is off by default — turn it on deliberately
when you need the reuse button, then run
[`h5p:generate-exports`](#command-line) once.

---

## Backend module

**Web → H5P** (`web_H5pManager`)

| View | Purpose |
|---|---|
| H5P content on current page | content on the selected page |
| All H5P content | complete listing |
| Add new | create content in the editor **or** upload a `.h5p` package |
| Libraries | installed content types |

Each row offers view, edit and delete. Deletion is a **two-step** process: one
click leads to a confirmation page listing the title, the library and the content
elements that reference the item; only submitting that form actually deletes.
This covers the record, its files, library associations and the export file — the
TYPO3 recycler does **not** apply here.

Libraries can only be deleted while no content and no other library uses them.
That check lives in the controller, so a hand-crafted link cannot cause damage.

---

## Frontend output

Four plugins are registered:

| Plugin | Kind | Purpose |
|---|---|---|
| `h5p_view` | content element | renders a piece of H5P content |
| `h5p_statistics` | content element | results for the logged-in user |
| `h5p_embedded` | plugin, page type `723442` | chrome-less output for `<iframe>` |
| `h5p_ajax` | plugin | endpoints for results and user data |

Below the content sits the H5P action bar with **Reuse**, **Rights of use** and
**Embed** — subject to the display options. With `embedType = iframe` the bar
lives inside the H5P iframe, so you will not find it in the outer DOM.

### Embedding

The embed button hands out an `<iframe>` snippet pointing at page type `723442`.
That page type is registered via
`ExtensionManagementUtility::addTypoScriptSetup()` in `ext_localconf.php` and is
therefore available without further setup — **no** static TypoScript template
needs to be included.

The URL currently takes the form

```
https://example.org/<page>?tx_h5p_embedded[contentId]=182&type=723442
```

Functional, but not pretty. Speaking URLs such as `/h5p/embed/182` would
additionally require a route enhancer.

---

## Import and export

### Export

With `enableExport` active, saving a piece of content produces
`fileadmin/h5p/exports/<slug>-<id>.h5p`. The reuse button in the frontend links
straight to it. Existing content that has not been saved since you switched the
option on has no such file — that is what `h5p:generate-exports` is for.

### Import

Under **Add new**, switch the radio button at the top to upload mode — the
template labels it `Aus .h5p-Datei hochladen` — choose the file and click
**Create**. The import runs through the core classes:
`H5PValidator::isValidPackage()` checks structure and file types,
`H5PStorage::savePackage()` creates the libraries and the content. Dependencies
are built afterwards so that the content loads its libraries in the frontend.

The edit form offers the same toggle, labelled `Durch .h5p-Datei ersetzen`. There
the content ID is preserved, so content elements referencing it keep pointing at
the same item.

> **A package must contain its libraries.** A `.h5p` without library folders is
> rejected by validation. This also affects your own exports of content that has
> no dependencies recorded — see [Known limitations](#known-limitations).

> **Security note.** A `.h5p` package contains executable JavaScript and will
> install new libraries if needed. If a package brings content types that are
> still missing, the import is aborted with a message for non-administrators
> rather than being carried out halfway — see [Permissions](#permissions).

---

## Command line

### `h5p:generate-exports`

Creates missing `.h5p` files for existing content.

```bash
./bin/typo3 h5p:generate-exports              # only fill in what is missing
./bin/typo3 h5p:generate-exports --alle       # also regenerate existing files
./bin/typo3 h5p:generate-exports --limit=20   # for a trial run
```

A full run takes minutes depending on how much content you have, and can occupy
double-digit GB. Try `--limit` first and check the resulting size.

### `h5p:find-duplicates`

Lists duplicated content with uid, slug, date and the number of content elements
referencing it. **Read-only** — which copy is the right one is a decision only
your editors can make.

```bash
./bin/typo3 h5p:find-duplicates
./bin/typo3 h5p:find-duplicates --nur-ungenutzt
```

Background: until a name collision on the `action` form field was fixed, every
save from the edit form created a **copy** instead of changing the content. This
command makes visible what piled up in the meantime.

### `h5p:cleanup-orphaned-files`

Removes folders under `fileadmin/h5p` that no longer have a matching record.

```bash
./bin/typo3 h5p:cleanup-orphaned-files              # list only (default)
./bin/typo3 h5p:cleanup-orphaned-files --force      # actually delete
./bin/typo3 h5p:cleanup-orphaned-files --force --include-deleted
```

**Without `--force` nothing is changed.** Folders belonging to soft-deleted
records are skipped — such records can be restored from the recycler, their files
cannot. Non-numeric folder names are never deleted.

> The command options above (`--alle`, `--nur-ungenutzt`) are German because the
> commands themselves are; they are spelled here exactly as the code defines them.

---

## Permissions

`Framework::hasPermission()` maps H5P's permissions onto TYPO3:

| Permission | Who is allowed |
|---|---|
| `UPDATE_LIBRARIES`, `INSTALL_RECOMMENDED`, `CREATE_RESTRICTED` | administrators only |
| `DOWNLOAD_H5P`, `EMBED_H5P`, `COPY_H5P` | everyone |

The first group installs or updates libraries, that is, third-party JavaScript —
which stays reserved for administrators. The second group merely controls whether
a button appears on a piece of content; that is for the editors to decide per
item.

Access to the module is governed as usual by the backend group via `groupMods`
(`web_H5pManager`) and `tables_modify`.

---

## Architecture

The extension translates between the H5P core and TYPO3. The core expects
interface implementations, which live in `Classes/Adapter/`:

| Class | Role |
|---|---|
| `Adapter/Core/Framework` | `H5PFrameworkInterface` — database, options, permissions, messages |
| `Adapter/Core/FileStorage` | `H5PFileStorage` — files via **FAL**, not via raw paths |
| `Adapter/Core/CoreFactory` | extends `H5PCore`, reads the export switch |
| `Adapter/Editor/EditorAjax` | libraries and translations for the editor |
| `Adapter/Editor/EditorStorage` | the editor's files |

Domain models and repositories under `Classes/Domain/` map the tables:

```
tx_h5p_domain_model_content              content
tx_h5p_domain_model_contentdependency    content -> library
tx_h5p_domain_model_contentresult        results
tx_h5p_domain_model_library              installed libraries
tx_h5p_domain_model_librarydependency    library -> library
tx_h5p_domain_model_librarytranslation   editor interface translations
tx_h5p_domain_model_contenttypecacheentry  the hub's content type catalogue
tx_h5p_domain_model_configsetting         H5P options
tx_h5p_domain_model_cachedasset           see Known limitations
```

On top of that, `tt_content` gains a `tx_h5p_content` field through which a
content element points at a piece of H5P content.

---

## Maintenance

### Two copies of the H5P core

This is the single most important trap in this extension:

| Location | Contents |
|---|---|
| `vendor/h5p/h5p-core/` | the **PHP code**, managed by Composer |
| `<extension>/Resources/Public/Lib/h5p-core/` | the **frontend assets** (JS, CSS, fonts) |

A `composer update` only refreshes the first half. If a new core version ships
additional stylesheets or scripts, they have to be copied **by hand** into
`Resources/Public/Lib/h5p-core/` — otherwise the frontend requests files that do
not exist.

Confusingly, the asset copy also contains an `h5p.classes.php`. The one actually
loaded is the one in `vendor/`. So when chasing an error message with a line
number, look in `vendor/h5p/h5p-core/`, not in the copy under `Resources/`.

Reconciling after a core update:

```php
// Lists what H5PCore expects against what the asset copy has
$ext = 'public/typo3conf/ext/h5p';  // path to the extension in your project
foreach (array_merge(H5PCore::$styles, H5PCore::$scripts) as $f) {
    printf("%-40s vendor:%s  assets:%s\n", $f,
        is_file('vendor/h5p/h5p-core/' . $f) ? 'ok' : 'MISSING',
        is_file($ext . '/Resources/Public/Lib/h5p-core/' . $f) ? 'ok' : 'MISSING');
}
```

Do not forget the fonts under `fonts/` that the stylesheets reference.

### After changing backend module actions

A new controller action needs **two** entries: the method **and** its
registration in `Configuration/Backend/Modules.php` under `controllerActions`.
Without the second, `f:uri.action()` produces an **empty** URL — the button shows
up but leads nowhere.

### Reserved argument names

Form fields must not be named `action` or `controller`: Extbase uses those
arguments to decide which action runs, and it does so before looking at the URL
path. That is exactly what made "Update" create copies upstream.

---

## Known limitations

**Asset aggregation does not work.** `FileStorage::cacheAssets()` was carried
over from a Neos/Flow port (`FLOW_PATH_WEB`, `resourceManager`) and would not run
under TYPO3. Accordingly `H5PCore::$aggregateAssets` is off, the `cachedasset`
table stays empty, and `deleteCachedAssets()` deliberately returns an empty
array. A targeted selection would lack the relation anyway: `CachedAsset`
declares an `ObjectStorage $libraries` for which neither an MM table nor a column
exists.

**Content hub.** The methods `replaceContentHubMetadataCache()`,
`getContentHubMetadataCache()` and relatives are placeholders. The
`CONTENT_HUB_METADATA_CACHE` and `GET_HUB_CONTENT` endpoints are not routed in
`EditorController`.

**User progress.** There is no `content_user_data` table;
`resetContentUserData()` is a documented no-op. `contentresult` stores finished
results, not intermediate state.

**Exports without libraries.** `h5p:generate-exports` reads dependencies from
`tx_h5p_domain_model_contentdependency`. Content with no entries there yields an
archive containing `h5p.json` and `content.json` but **no library folders** — a
few KB in size and not valid on import. You can spot these by file size: anything
under ~5 KB is suspect.

**Deliberately not implemented**, because the installed core version never calls
them: `getNumNotFiltered()`, `getLibraryUsage()`, `getAdminUrl()`. Likewise
`lockDependencyStorage()`/`unlockDependencyStorage()` — legitimate no-ops on most
platforms — as well as `Event::save()`/`saveStats()`, for which the table is
missing.

---

## Troubleshooting

**The reuse button leads nowhere.** The export file is only created on save.
Check `enableExport` and run `h5p:generate-exports`.

**The "Allow users to download" and "Display Embed button" checkboxes stay
empty.** This is not a saving bug: if `displayOptionDownload` or
`displayOptionEmbed` is set to `3` (always) or `0` (never), H5P ignores the
checkboxes and the extension hides them. Set them to `1` or `2` to make them
effective.

**Uploading a `.h5p` file aborts.** If the package is missing libraries, only an
administrator may install it. The message says so; otherwise read the validator's
error messages, which name the offending file.

**An action in the backend module does nothing.** Check whether it is registered
in `Configuration/Backend/Modules.php` (see [Maintenance](#maintenance)).

**The frontend reports missing CSS files.** The asset copy has fallen behind the
core version — see [Two copies of the H5P core](#two-copies-of-the-h5p-core).

**Stubs stay silent.** `Utility\MaintenanceUtility::methodMissing()` only throws
in the Development context. In Production, unimplemented methods quietly return
`null`. To hunt for gaps, switch the context to `Development` temporarily.

---

## Differences from upstream

This version differs considerably from `michielroos/h5p`. Among other things the
`.h5p` upload was repaired; export, import, the deletion path and embed output
were made to work at all; the permission check was wired up; and three CLI
commands were added.

Before working on this extension, a look at [Maintenance](#maintenance) and
[Known limitations](#known-limitations) pays off — that is where the pitfalls
that cost the most time during the rework are written down.

---

## License and origin

GPL-2.0-or-later. Original extension by **Michiel Roos**
(<https://www.michielroos.com>). H5P itself is a project of the H5P Group, see
<https://h5p.org/>.
