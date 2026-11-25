# Hostinger deployment without SSH

This release is prepared for PHP 8.3+, an empty dedicated MySQL database and
`https://fair-sandybrown-kangaroo-55.hostingersite.com`. The final domain can be
changed later. FTP certificate verification failed, so use hPanel File Manager;
do not disable certificate checks or transmit credentials using plain FTP.

## Protected public_html layout for Git deployment

The existing installation can be migrated to the following layout when Git
deployment is restricted to `public_html`. This is a separate procedure from the
original two-ZIP first installation below.

```text
public_html/
  .htaccess                  # blocks the protected app, dotfiles and .git
  index.php                  # loads yesenterprise-app and sets public_path here
  build/, css/, js/, media/   # only intended public assets
  yesenterprise-app/
    .htaccess                # Require all denied
    .env                     # existing production environment; NEVER in Git
    app/, bootstrap/, config/, database/, lang/, resources/, routes/
    vendor/                  # built production dependencies, included in release
    storage/                 # existing private uploads/runtime; NEVER in Git
```

HTTP protection does not prevent PHP from reading its own environment or storage.
It does depend on Apache enforcing `.htaccess`. Do not copy secrets below the
document root until the actual hosting URL passes the protection checks.

### Prepare the production snapshot

From the repository in PowerShell:

```powershell
.\scripts\Deploy-Website.ps1 -PrepareGitRelease
```

This builds production Composer dependencies and frontend assets, verifies live
MySQL using TLS, and refuses to prepare a release if database migrations are
pending. It does not use FTP, push Git commits, seed the database, change an admin
account, or upload anything. Output is in the private local deployments folder:

- `git-release/`: the snapshot for a dedicated `production` Git branch, not `main`.
- `.deploy-protection.zip`: Apache protection files and harmless access probes.
- `.deploy-cutover.zip`: only the new public entry point.
- `.deploy-maintenance.php`: a temporary maintenance response for the migration.
- `git-release-report.json`: build/provenance and read-only database checks.

Publish only `git-release/` to the production branch, never its parent folder.
The snapshot intentionally contains no environment file, SQL dump, storage,
owner-uploaded media, or local credential/build input. Original source/vendor
`.gitignore` files are omitted so they cannot accidentally exclude built
dependencies or assets. Generated provider manifests remain included; server
config/route caches are ignored.

### Check Hostinger's data preservation before touching the live directory

Native Git deployment must retain files that are not tracked by the deployment
branch. Do not assume every hosting deployment implementation does this.

1. Keep live auto-deployment disabled and back up the existing site/database.
2. In a disposable staging subfolder, create a protected `yesenterprise-app`
   directory with `Require all denied` in its `.htaccess`, an `.env` containing
   only non-secret test values (`APP_DEBUG=false`), and a dummy file in
   `storage/app/private/`.
3. Deploy the prepared production branch to that staging subfolder using hPanel.
   Verify the dummy environment and storage file remain intact in File Manager.
4. Redeploy and verify they remain intact again. Verify direct HTTP requests to
   the staging private app return 403.
5. If either file disappears, **stop**. Do not deploy into the live directory or
   enable auto-deployment: that host's Git integration cannot safely preserve
   runtime data with this layout. Use a preservation-aware updater instead.

This staging preservation probe does not need production credentials or a working
database. Its homepage can fail with the dummy environment; check retention in
File Manager and private-path denial separately.

The release updates tracked supplier/default media; do not manually modify those
files on hosting. Owner-uploaded media belongs in `media/uploads`, which is
excluded from the production branch. Admin catalogue edits remain in MySQL and
are not overwritten or reseeded.

### One-time migration of the live installation

After staging preservation succeeds:

1. Back up the existing private app (including hidden `.env`), its storage, public
   uploads, and database. Keep those backups outside the web-accessible area.
2. Upload `.deploy-protection.zip` into `public_html` and extract it, replacing
   the root `.htaccess` and creating the protected app's `.htaccess`. The existing
   public entry point still uses the original sibling app at this point.
3. The archive also creates non-secret `deployment-access-probe/check.*` files
   inside the protected app. These avoid mistaking a missing file or a proxy's
   dotfile-only block for directory protection. Do not put production values in
   those files. Run:

   ```powershell
   .\scripts\Deploy-Website.ps1 -VerifyProtection
   ```

   This requires healthy HTTPS pages and actual HTTP 403 responses for private
   environment, source, vendor, logs and Git paths, plus existing PHP, JSON,
   PDF/image, CSS/JavaScript and text probes. A missing-file 404 is not proof that
   the directory is protected. Stop on any failure.
4. Upload the supplied maintenance response into the **original sibling app's**
   `storage/framework/maintenance.php`. Confirm `/en` returns 503 before copying.
5. Copy the original `yesenterprise-app` directory into `public_html`, merging
   into the protected folder without removing its protection. Include hidden
   `.env` and all storage, keeping the original sibling as a recovery copy.
   Do not regenerate `APP_KEY`, recreate the admin, or import first-install SQL.
6. While maintenance remains active, deploy the `production` branch into
   `public_html` through hPanel. It must preserve the copied environment and
   storage just as the staging test proved. Do not deploy the source-only `main`.
   The release entry point loads the newly protected app. If testing a manual
   cutover first, `.deploy-cutover.zip` provides that same entry point.
7. Remove stale `bootstrap/cache/config.php` and `bootstrap/cache/routes-v7.php`
   from the **new protected app**, if present. Leave its fresh provider manifests.
   Verify the environment and storage still match the originals, then remove
   only the migration's maintenance marker from the new app.
8. Run `-VerifyProtection` again. Test the existing production admin login,
   multilingual pages, photos, and private resume downloads through the admin.
   Do not enable auto-deployment until these checks pass.

Do not delete the original recovery copy until the migrated installation is
verified. Never restore an old database backup over newer submissions. If rolling
back after new uploads arrive, preserve the current storage/uploads before
changing the entry point.

For routine Git releases, rebuild the production snapshot and publish it to the
same production branch without force-pushing:

```powershell
# After committing source changes; this explicitly authorizes a production push.
.\scripts\Deploy-Website.ps1 -PrepareGitRelease -PublishGitRelease
```

This clones or initializes a separate production working copy, carries over
source-repository author/signing/hooks settings, commits the built snapshot and
pushes normally. It never switches or rewrites the source working tree, never
force-pushes, and refuses dirty source or a branch tracking runtime data. Keep
hosting auto-deployment disabled during the initial migration. A successful Git
push alone does not prove that hosting deployed successfully; verify hPanel,
HTTPS and data preservation afterwards.

Source-only commits to `main`
should not trigger hosting deployment. Git does not run Laravel migrations or
clear server-generated config/route caches: migration-bearing releases require
a separate backed-up maintenance/migration deployment, and this preparation
command deliberately blocks them.

## Release files

The generated release folder is under the owner's local secret directory.

| File | Destination / purpose |
| --- | --- |
| `yesenterprise-private.zip` | Extract **outside** `public_html`; contains Laravel, production dependencies and the production `.env` |
| `yesenterprise-public.zip` | Extract **inside** `public_html`; contains only public assets and the entry point |
| `yesenterprise-database.sql` | Import into the dedicated empty database with phpMyAdmin; never upload to a public folder |
| `release-report.json` | Local checksums and verification summary; not a server configuration file |

Treat the private ZIP and SQL as confidential. Do not share them publicly.
The SQL contains only the clean schema/catalogue and one authorized administrator,
not development users, enquiries, resumes, sessions, jobs or logs. The admin uses
the email/password supplied privately, stored in the SQL only as a password hash.
Media publication approvals were explicitly confirmed for this release.

## Upload

1. In hPanel, select the temporary-domain website and open File Manager with
   access to all website files, not a view restricted to `public_html`.
2. Confirm the deployment location is empty. Do not overwrite or delete
   unexpected existing content; download a backup and resolve it first.
3. At the directory containing `public_html`, upload and extract
   `yesenterprise-private.zip`. It creates a sibling directory named
   `yesenterprise-app`.
4. Inside `public_html`, upload and extract `yesenterprise-public.zip`. The files
   must be directly in `public_html`, not in another nested directory.
5. Remove the two uploaded ZIP files through File Manager after extraction,
   especially the public ZIP. Keep the local copies securely.
6. Check the resulting layout, including hidden files:

   ```text
   website-root/
     yesenterprise-app/
       .env
       .htaccess
       artisan
       app/
       bootstrap/
       config/
       database/
       lang/
       resources/
       routes/
       storage/
       vendor/
     public_html/
       .htaccess
       index.php
       assets/
       build/
       css/
       fonts/
       js/
       licenses/
       media/
       vendor/
       ...public CSS/JavaScript files
   ```

   If File Manager cannot create the private sibling directory, **stop**. Do not
   place this credential-bearing initial release into an unprotected public
   folder. Arrange private directory access, or use the separately verified
   protected Git migration procedure above.

The packaged public entry point already loads the private application from this
layout and sets Laravel's public path correctly. No symlinks or browser-accessible
installer scripts are required. Storage and bootstrap cache directories must be
writable by the hosting PHP account; start with owner-writable 755 directories
and 644 files, `.env` 600. Do not use 777 permissions.

## Database import

1. Open phpMyAdmin for the dedicated database named in the private configuration.
2. Confirm it contains **zero tables**. This import is for a fresh installation,
   not an upgrade or merge into another website's database.
3. Import `yesenterprise-database.sql` from the local release folder.
4. Confirm import succeeds. The database should contain 507 products, one admin
   user, and zero submissions/sessions/jobs before the first website visit.
5. Verify `yesenterprise-app/.env` has the correct hosting database values using
   File Manager's private editor. Do not paste its contents into chat or support
   screenshots.

The production environment has a fresh application key, debug disabled,
HTTPS-only encrypted sessions, indexing enabled and synchronous delivery.
No queue worker or cron is required for current enquiry notifications.
Do not change or regenerate the application key after users begin submitting
information.

## Verify before declaring launch complete

- Enable/confirm HTTPS and Force HTTPS in hPanel. PHP errors must not be displayed.
- Open `/en`, `/gu` and `/hi`; check catalogue, photos, galleries and mobile layout.
- Open `/up`, `/sitemap.xml` and `/robots.txt`. Robots should exclude `/admin` and
  `/private` and point to the HTTPS sitemap. The `.htaccess` explicitly routes
  robots through Laravel even though a static file exists.
- Confirm `/.env`, `/composer.json`, `/storage/logs/laravel.log` and
  `/yesenterprise-app/.env` are unavailable publicly. Do not publish diagnostic
  `phpinfo`, database-import or installation scripts.
- Log in at `/admin` using the production account. Confirm dashboard, editors
  and photo uploads work. New uploads continue to require independent approval.
- Submit one clearly identified test enquiry through Contact with consent.
  Confirm it appears in admin and reaches the configured Gmail inbox.
- Test a fictional career application; verify only an authenticated administrator
  can download its document.

Gmail SMTP credentials are configured, but authentication from the preparation
machine disconnected and was **not verified**. The definitive delivery check
must be performed from the hosting server. If delivery fails, the site preserves
the enquiry with a truthful pending status; inspect private logs and use the
admin retry after fixing SMTP. Do not claim that email succeeded merely because
the enquiry was saved.

The Gmail account is used as both sender and enquiry recipient, as requested.
It uses `smtp.gmail.com` with STARTTLS on port 587 (or implicit TLS on port 465
if selected in the private configuration) and a Google App Password.

## Change to the final domain

1. Attach the final domain to the same website directory and enable its HTTPS.
2. Edit `APP_URL` and `MAIL_EHLO_DOMAIN` in the private `.env`.
3. Keep `SESSION_DOMAIN=null` unless an intentional cross-subdomain policy is
   needed. Users must log in again on the new domain.
4. The release has no cached configuration/routes/views with preparation-machine
   paths. If configuration caching is enabled later, clear/rebuild it when
   changing the domain.
5. Verify canonical URLs, language alternates, sitemap, admin and email delivery.
6. Set up a permanent redirect from the old domain if Hostinger permits it, then
   submit the new sitemap in Google Search Console.

## Backups

Back up the production database, private `.env`/application key, private resumes
and owner-uploaded media before updates. Restrict backup access and keep backups
outside the public webroot. Follow the approved retention policy for submissions.
This first-installation SQL must not be reimported into a populated live database.

## Automated updates without SSH

After the first installation, run the PowerShell script from the repository:

```powershell
# Preview: verifies connections, builds assets and lists changes; no remote writes.
.\scripts\Deploy-Website.ps1

# Deploy files and pending Laravel migrations, then check the live HTTPS site.
.\scripts\Deploy-Website.ps1 -Apply
```

Requirements:

- Python 3.10+, Docker Desktop and Node/npm. Use the existing development
  `yesenterprise-app` Docker image; build it with `docker compose build app`
  if it does not exist. Restore frontend dependencies with `npm ci` in `website`
  if the frontend build reports they are missing.
- The private credentials file has `MYSQL` and `FTP` sections with the same
  `host`/`hostname`, `port`, `database`, `username`, `password` and `directory`
  fields used for the initial package. SMTP/admin sections are not used for updates.
- FTPS must use a certificate-matching hostname and explicit TLS on port 21.
  Credentials and file transfers are encrypted; there is no certificate-bypass
  or plain-FTP option.
- Hostinger Remote MySQL must allow the deploying computer's public IP. Allow
  only that IP, not **Any Host**. The database connection must support TLS with
  a trusted, hostname-matching certificate. Remote MySQL authentication was
  successfully verified during preparation; it may need updating if your IP changes.
- The initial layout remains `yesenterprise-app` beside `public_html`, and the
  FTP account can access both directories. This script updates an existing
  installation, not an empty or differently arranged hosting account.

The script builds the frontend and production Composer dependencies locally.
It compares file hashes and uploads changed code/assets; it reuses live vendor
files when `composer.lock` is unchanged. Existing media files are never overwritten,
and owner uploads are excluded. It does not prune unknown remote files.

The production `.env`, application key, admin account, sessions, resumes and
storage are preserved. No seeder, `migrate:fresh`, database reset or new admin
creation is run. Changes made through admin remain authoritative; editing seed
JSON locally is not an instruction to overwrite the live catalogue.

An Apply deployment:

1. Verifies FTPS and MySQL TLS, migration history and the existing private layout.
2. Saves copies of replaced files in the owner's private local deployment folder.
3. Creates a temporary 503 maintenance page before changing runtime files.
4. Takes a full database backup when migrations are pending; failed backups stop
   deployment before any migration.
5. Uploads changed files using temporary names and rename, with `index.php` last.
6. Runs pending Laravel migrations from the local container against remote MySQL,
   checking that user/product/submission counts did not unexpectedly change.
7. Removes only recognized Laravel config/route caches, records hashes, and
   removes its own maintenance marker.
8. Verifies `/up` and `/en` over HTTPS. Email delivery is not tested automatically.

Run one deployment at a time. Do not edit production source files through File
Manager while an update is running.

Backups and logs are written under a `deployments` subdirectory beside the
private credentials file. Database backups and cache copies can contain sensitive
information: keep them confidential and never upload them to `public_html`.
Temporary local credentials and build containers are cleaned up.

If deployment fails during upload/migration, the script leaves maintenance active
and reports failure. Review the private logs. Restore affected files from
`files-before.zip` if appropriate, then remove **only**
`yesenterprise-app/storage/framework/maintenance.php` through File Manager after
checking the application. Do not delete an unrelated maintenance marker.
Database rollback is deliberately not automatic: restoring a database can lose
live submissions and needs a deliberate recovery decision.

If the script reports that uploads/migrations completed but HTTPS health checks
failed, verify the site immediately; do not assume it is healthy. Deployments
should use backward-compatible migrations. Dependency updates may transfer many
vendor files and keep the site in maintenance longer than ordinary CSS/code changes.

To use another secret file or the final domain:

```powershell
.\scripts\Deploy-Website.ps1 -Apply `
    -SecretsFile "C:\path\to\private\passwords.txt" `
    -Url "https://your-final-domain.example"
```

`-Url` controls post-deployment checks, not production configuration. Update the
private live `.env` and domain/HTTPS settings as described above when changing
domains. The update script never copies a local `.env` over production secrets.
