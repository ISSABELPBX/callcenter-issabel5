# patches/ — per-version update payloads

`build/5.0/update-issabel-callcenter.sh` updates an installed Call Center in
place by walking these directories: every version newer than the installed
one, in ascending order, applies that version's `update.sh` and then copies
its `files/` tree over the installation as-is.

## Layout

    patches/<VERSION>/
    ├── update.sh                 mandatory, always present
    └── files/<repo path>         the changed files, overwritten as-is

- One directory per **released** version that changes a file shipped to the
  box, named exactly as that release's `VERSION` (e.g. `patches/5.1.4/`).
  A version that changes nothing on the box has no directory; the updater
  still brings the installed stamp up to date.
- `files/` mirrors the repository layout, and may contain only paths that
  ship to the box (the updater's mapping, from `callcenter-common.sh`):
  - `modules/…`                     → `/var/www/html/modules/…`
  - `setup/dialer_process/dialer/…` → `/opt/issabel/dialer/…`
  - `setup/dialer_process/issabeldialer.service` → `/etc/systemd/system/`
  - `setup/issabeldialer.logrotate` → `/etc/logrotate.d/issabeldialer`
  - `setup/callcenter-modules.logrotate` → `/etc/logrotate.d/callcenter-modules`
  - `setup/issabel-sse.conf`        → `/etc/httpd/conf.d/`
  - `setup/usr/bin/…`               → `/usr/bin/…`
  - other `setup/…`                 → `/usr/share/issabel/module_installer/callcenter/setup/…`
  - `menu.xml`, `CHANGELOG_OLD.md`  → `/usr/share/issabel/module_installer/callcenter/`
  - `VERSION` never appears here: the updater writes the stamp itself.
  Anything else (docs, `build/`) makes the updater refuse the whole plan.
- Each file under `files/` is the file **as of that version** — bytes taken
  from the release, not from a later tree.

## Filling a directory for a new version

    git diff --name-status <previous VERSION bump> <this VERSION bump>

Copy every changed path that ships to the box (see the mapping above) into
`files/` at the same relative path, with its content at the new version.
Deletions cannot be expressed by a copy: remove deleted files in `update.sh`.

One exception: 5.1.2 starts from the 5.1.1 RC release (`47a702b`), not from
the 5.1.1 bump (`54ec605`) — work went on under 5.1.1 after the bump, and the
RC is what 5.1.1 boxes run. So its range is `9836bb6^ 418b3aa`.

- A **schema change** ships as SQL inside `update.sh` — idempotent, with the
  root password only in `MYSQL_PWD` — and never as a copied file:
  `setup/call_center.sql` and `setup/installer.php` are changed in the repo
  for fresh installs but are **not** put under `files/`. That is safe because
  the updater never runs either file, and a full install re-copies `setup/`
  from the checkout before `installer.php` runs, so the copy an updated box
  carries is never the one that executes.

## update.sh — the database and configuration changes

Mandatory in every version directory, even when there is nothing to do: then
it is a short script whose comment says why (a release with only code changes
carries a no-op with that reason). The updater refuses a version directory
without one.

Contract:

- Run by the updater with the repository root as working directory, and with
  `CC_PATCH_VERSION` (the version being applied), `CC_PATCH_DIR` (its
  directory) and `CC_REPO_ROOT` in the environment.
- Must be **idempotent**: after a version completes, the installed stamp is
  written, and a failed later version leaves a re-run to apply the remaining
  ones again — so a re-applied `update.sh` must be harmless.
- Exit 0 on success. Any other exit stops the whole update; the message the
  updater prints tells the operator that a re-run resumes from the last
  completed version.
- Owns everything a file copy cannot do: database migrations, deletions, and
  Asterisk or Issabel configuration. For configuration that the installer
  itself installs (dialplan contexts, the parking lot, `agents.conf`), the
  config-only pattern is to run the repository's `setup/installer.php` with
  no module staged under `/tmp/new_module`: its database block only runs
  when that staging directory exists, so a plain
  `php "$CC_REPO_ROOT/setup/installer.php"`
  re-installs the contexts, the parking lot and the agent entries without
  touching the database.
- Database credentials are read from `/etc/issabel.conf` (mysqlrootpwd) at
  run time, and **never printed** — never in an argument, a log line or an
  error message.
- Do not restart services from `update.sh`: the updater restarts
  `issabeldialer` and reloads (never restarts) Asterisk once, at the end,
  when files were copied.

## Resume

The updater writes the deployed stamp (`/usr/share/issabel/module_installer/
callcenter/VERSION`) after each version completes, so an interrupted update
resumes at the first version that did not finish. A failed `update.sh` is
therefore safe to re-run after fixing it.
