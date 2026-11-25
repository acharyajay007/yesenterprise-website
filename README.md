# Yes Enterprise website

Laravel 13 / Filament 5 on PHP 8.3, with an Industro-based public website,
MariaDB and Mailpit for Docker development.

For Hostinger Git deployment restricted to `public_html`, use the
[protected production layout](docs/HOSTINGER-DEPLOYMENT.md#protected-public_html-layout-for-git-deployment).
`.\scripts\Deploy-Website.ps1 -PrepareGitRelease` builds a complete production
snapshot without credentials, private uploads, database changes or a Git push.
Deploy its dedicated production branch, not the source-only `main`; verify
hosting protection and untracked-data preservation before migrating the live site.
For a migration without File Manager uploads, publish the temporary `bootstrap`
branch with `-BootstrapGitRelease`; it retains the original entry point while
authenticated HTTPS operations back up and copy private runtime server-side.
Follow the [Git-only migration procedure](docs/HOSTINGER-DEPLOYMENT.md#git-only-migration-without-file-manager-uploads)
before switching hPanel to `production`. Production credentials never enter Git.

The owner-supplied logo is displayed beside **Yes Enterprise / Rajkot** in the
public header/footer and admin panel, and is also used as the favicon.

## Local development

From this repository in PowerShell:

1. Start Docker Desktop. Copy `.env.example` to `.env`, set a randomly generated
   `DEV_DB_PASSWORD`, and choose an unused `DEV_HTTP_PORT` (default `8180`).
   The root `.env` contains local development secrets and is ignored by Git.
2. Run:

   ```powershell
   docker compose build app
   docker compose run --rm --no-deps app composer install --no-interaction
   Copy-Item .\website\.env.example .\website\.env
   docker compose run --rm --no-deps app php artisan key:generate
   docker compose up -d database mailpit
   docker compose run --rm app php artisan migrate --seed
   docker compose up -d app
   ```

3. Build local frontend assets (Node.js 22.12+):

   ```powershell
   Set-Location .\website
   npm ci
   npm run build
   Set-Location ..
   ```

   Repeat the build after changing frontend modules. Deploy the generated
   `website/public/build` directory alongside the PHP application; Node.js is
   only needed for building, not on the PHP/MySQL hosting server.

4. Open <http://localhost:8180/en>, <http://localhost:8180/admin> and
   <http://localhost:8025> (Mailpit). Use your selected port if different.
5. Create an administrator interactively:

   ```powershell
   docker compose exec app php artisan website:create-admin your-email@example.com
   ```

   This prompts for a password. It will not modify an existing account.
   On the initially prepared development instance, credentials for the fictional
   `admin@example.invalid` account are in the ignored root `.env` under
   `DEV_ADMIN_EMAIL` / `DEV_ADMIN_PASSWORD`. No credentials are committed.

Composer dependencies and its cache are in named Docker volumes to avoid
Windows bind-mount extraction timeouts. Public images are served from
`website/public/media`; private resumes are in
`website/storage/app/private/resumes`, never in the public media directory.
Changing Compose environment variables requires recreating the app container.
The development server uses `--no-reload` to preserve container environment values.

Use `docker compose stop` to stop services without deleting database volumes.
Do not use `down -v` unless you deliberately want to destroy local data.

## Implemented

- Server-rendered English, Gujarati and Hindi pages, catalogue filtering, product
  details, About, gallery, Contact, Careers and submission privacy information.
- Awning banner, homepage customer-project showcase and a dedicated `/en/awnings`
  supply-and-installation page (also Gujarati/Hindi), linked in navigation and sitemap.
  Thirteen owner-supplied customer installation photos are approved for publication.
  The awning page has a photo-led hero, installation portfolio, application and
  process sections, fabric cards and compact enquiry links. Public photo captions
  describe the scene; ownership/source classifications remain admin-only.
- Four official supplier logos; no current authorization claim.
- Homepage hero powered by the locally bundled Splide npm package, with compact
  arrows, a slide counter, pause/play, touch/keyboard support and reduced-motion
  handling. It advances every five seconds, including while hovered; reduced
  motion disables the transition, not timed advancement. Pause/play remains
  available, focus pauses advancement, and galleries preserve a manual pause. Hidden tabs
  use browser animation-frame suspension; returning does not permanently pause it.
  The editable Home banner is followed by all 13 material categories, using
  dedicated native-HD supplier application photographs, not product thumbnails.
  Manage their order, images, private sources and independent rights approvals
  in Pages > Home > Homepage category banners. Unpublished categories and
  unapproved production images are excluded. Banner defaults initialize only
  null data, preserving edits and intentionally empty lists. The old default
  Home thumbnail alone is upgraded; owner-uploaded Home images are preserved.
  Source URLs, native dimensions and review context are recorded in
  `website/database/seeders/data/hero-banners.json`; no thumbnail upscaling is used.
  Hero assets are independent of product photos and actual customer awning photographs.
  Hero photos do not open a viewer.
  Retain `website/public/licenses/splide.txt` with deployed frontend assets.
- The transparent YE logo has no forced white background. The topbar/footer call
  links, Contact page, store structured data and floating WhatsApp chat use the
  owner-confirmed public numbers in `website/config/website.php`. Chat opens
  WhatsApp; no message is sent automatically.
- Admin catalogue/photo/specification editing, localized homepage/banner/awning
  editing, editable About/Contact/Awning summaries and SEO descriptions, gallery
  management, submission review and private resume downloads.
- Professional About page with material groups and enquiry approach; Contact
  page with location, business hours, product-aware enquiry and submission guidance.
  About uses the supplied storefront team photo without cropping people; its
  photograph remains editable in admin and subject to the media-approval gate.
- Persisted SMTP enquiries and career applications; PDF/DOCX documents up to
  5 MB; shared limit of five attempts per fifteen minutes per client IP.
- Notifications are synchronous with a bounded SMTP timeout. SMTP failure leaves
  the record saved with a truthful pending message and an admin retry action.
  Resumes are not email attachments. Development SMTP goes only to Mailpit.
- Canonicals, reciprocal language alternates, localized metadata, sitemap and
  business structured data. Development is noindex.

The original mockup remains a session artifact, not a production JavaScript SPA.
The actual website persists changes and submissions in the local database.

### Popular products and photography

- Products support ordered additional photos with English/Gujarati/Hindi captions,
  internal provenance and independent publication approval in admin. Reseeding
  preserves owner edits, including intentionally empty galleries.
- Product, customer-project, gallery and About photos open a shared full-screen
  viewer with thumbnails, previous/next, keyboard navigation, zoom and mobile
  swipe. Product-title/details links still navigate to the product. Supplier
  logos and homepage hero images are not viewer links.
- In admin Products, enable **Popular product** and set **Popular display order**
  (lower first). The homepage shows up to eight published popular products.
  Badges appear on catalogue cards and details. This is a curated selection,
  not a measured sales ranking. Initial selections remain editable.
- Catalogue supports popular-only filtering, popular-first, name A–Z / Z–A and
  newest-added sorting. Search, filters and sort persist across pagination and
  language switching. Unpublished products are excluded.
- The 507-product catalogue prioritizes exact-model sources, then verified
  same-brand/product-family photographs. The reviewed defaults use 207 native
  image assets, including official supplier model galleries and 15 labelled LX
  material swatches. Originals are never enlarged. Source associations, native
  dimensions and image hashes are recorded privately in
  `website/database/seeders/data/reviewed-photo-upgrades.json`.
- Seventy-six unmatched Jindal/3M defaults use clean placeholders instead of
  misleading cross-brand photos or product-page header graphics. Family applications
  are not presented as exact-model photographs. Photo classifications and source
  URLs remain admin-only; public pages show no sourcing warnings.
- Catalogue photos remain full-width. Rolls/packages use complete-product framing;
  applications/textures retain cover framing. Change **Catalogue photo framing**
  in admin; detail photos and the full-screen viewer already show complete images.
- Run `php artisan migrate` and `php artisan db:seed --class=WebsiteSeeder` to
  apply reviewed defaults. Existing photos upgrade only when their entire original
  generated image/source baseline still matches (including known pre-provenance
  defaults with null photograph sources). Approved photos, owner uploads,
  custom provenance/framing and existing galleries (including empty lists) are
  preserved. Replacements never inherit an old photograph's publication approval.
- Admin Products offers **Photo follow-up** filters for missing images, exact-model
  replacements and publication approval. Upload a verified image, choose the
  factual caption, record its source and approve publication rights when known.
  Supplier images remain hidden on an indexable site until individually approved.
- Admin Pages controls the Awning page summary, SEO text and lead photo; Gallery
  controls the customer project photos, captions, visibility and media approval.
  New awning gallery photos use the **Actual customer awning installation** type.
- Internal warnings are admin-only: the dashboard's **Website review & source
  warnings** widget contains development/indexing status, photo follow-up counts,
  publication notes and supplier coverage gaps. Product source notes and image
  provenance remain editable in Products. Public banners, stock/source caveats
  and warning captions are hidden. Unconfirmed micron measurements are retained
  in admin but omitted from public specifications, rather than presented as certain.
  Form errors, truthful submission status, privacy/consent and media approval
  gates remain active; removing banners does not enable search indexing.

### Supplier catalogue coverage

The catalogue now seeds **507 entries**, rather than eight starter entries.
There are 24 entries per page, with preserved search/brand/category filters.
New categories include textiles/canvas, wallpapers, decorative glass films,
reflective media, synthetic print media, display films and signage lighting.

The versioned `website/database/seeders/data/supplier-products.json` contains
504 unique supplier definitions and maps all 524 audited source entries to them.
Five definitions reuse starter-product slugs. `firstOrCreate` preserves existing
admin edits, publication flags, photographs and specifications when reseeding.
Additional photographs retain source URLs; 189 distinct supplier images were
downloaded and decoded successfully.

- **Sun Sign & Technologies:** all 176 product sitemap pages read; duplicate
  NSBO-3013, NWP-01 and SAF Premium pages grouped. This includes its Sunstar,
  Starflex, specialty-media and signage-lighting offerings. Explicitly labelled
  numerical specifications were added for 110 definitions; ambiguous values
  and thickness measurement bases are not inferred.
- **SRF:** 14 coated-fabric families, three laminated-fabric families and six
  named laminated brand ranges. Missing SKU-to-weight mappings are not invented.
- **Jindal / Jinflex:** all 26 codes across four tabs. BB260, TAR460 and TAR510
  have inconsistent supplier weight mappings; disputed weights are omitted.
- **LX VIZUON:** all 206 full model/colour codes found in the current published
  2025 global catalogue are represented, with catalogue-page references.
  The original 20 accessible European entries are retained or merged by code.
  This covers published full codes, not every size variant or historical
  datasheet. European-listing parity and Indian availability remain unconfirmed.
- **3M:** 73 named models/families from official Indian search-index listings.
  Direct pages returned access errors; no unverified technical figures are
  imported. This is not all colour/size SKUs or the complete 3M catalogue.
- **Ultra Sign / HiSign / NEO:** current official sources remain unavailable.
  Unknown products are not fabricated or attributed to generic sheet stock.

Catalogue coverage and gaps are available only in the authenticated admin. Product titles
retain manufacturer names/codes; new descriptions and source caveats are original,
localized copy. These are supplier-listed enquiry options, **not live-stock,
authorization, installation or warranty claims**. Admin can edit internal source caveats.
Vehicle application images have not been reintroduced into the main banner.

The local expansion added missing records and enriched 126 existing source-default
records. Specifications, source URLs and localized source notes were changed only
when still equal to the previous import defaults; existing overrides were kept.
Routine reseeding continues to preserve all existing records. About and Contact
page summaries are also seeded only when absent, preserving subsequent admin edits.

## Validation

```powershell
docker compose run --rm --no-deps app php artisan test --compact
docker compose run --rm --no-deps app vendor/bin/pint --test
docker compose run --rm --no-deps app php artisan view:cache
```

Tests force both PHP server/environment variables to SQLite `:memory:` and use
an isolated temporary compiled-view directory. They do not refresh the running
MariaDB database or share its session/cache stores. Fixtures use fictional data.
Real browser validation also covers the three languages at desktop/mobile widths.

The public site loads local CSS/JS, including the built Vite hero-slider bundle.
Filament's assets are published by Composer's post-autoload script.

## Before production

For the no-SSH File Manager deployment layout and database import procedure, see
[Hostinger deployment](docs/HOSTINGER-DEPLOYMENT.md).
After the initial installation, `.\scripts\Deploy-Website.ps1` previews changes;
add `-Apply` to deploy through verified FTPS and run migrations through verified
remote MySQL. The automation preserves production secrets and owner data.

This is a working **development build**, not an approved Hostinger deployment.

- Confirm public contacts, canonical spelling and exact stock. The owner has
  confirmed awning supply and installation; pricing, site suitability, service
  area and arrangements remain enquiry-specific. Motorized assemblies are not assumed.
- Review Gujarati/Hindi translations and all supplier/owner media permissions.
  Images without `media_approved` are hidden when indexing is enabled.
  Logos still require an owner-approved publication decision.
- Confirm resume retention/deletion, authorized readers and the production
  privacy contact. Submission deletion is deliberately disabled until a policy
  is agreed. The current privacy page is draft operational information.
- Configure real SMTP credentials, a verified sender and the confirmed enquiry
  recipient. The previously supplied temporary recipient can be configured
  privately, not displayed as the business's public email.
- Keep `APP_DEBUG=false` and `WEBSITE_INDEXABLE=false` until launch approval.
  Do not rely on `robots.txt` as access control.
- Use a dedicated production database; do not migrate the existing Hostinger
  database without explicit isolation and permission.
- Hostinger Single has no assumed SSH/Composer/worker support. Prepare `vendor`
  and published assets locally, review/export versioned SQL, and verify a
  release arrangement exposing **only** Laravel's `public` directory.
  `.env`, source and private storage must remain outside the webroot.
  Production Docker and Laravel's development server are not deployment options.
- Confirm required extensions, private writable storage and backup arrangements.
  Verify server rules prohibit execution/listing in public media uploads.

Industro source styles and its supplied license are retained in
`website/public/vendor/industro`. The footer displays only the business copyright;
attribution-free deployment requires a valid HTML Codex credit-removal license.
The free template does not include Pro pages or a working form backend.
Supplier imagery is labelled by provenance, not presented as exact stocked
models or Yes Enterprise's completed installations.

### Admin workspace theme

The admin uses the MIT-licensed `irajul/filament-shadcn-theme` package for
Filament 5, with the Nova preset, zinc surfaces, orange accents, rounded panels
and light/dark modes. The plugin supplies its theme CSS without a Node build.
Local dashboard card styles are in `website/public/admin.css`.
Keep the package's MIT notice in the deployed Composer vendor directory.

Navigation groups catalogue, website content and incoming requests. The dashboard
has working management shortcuts and linked database-backed statistics. Review
notices remain admin-only in a collapsible section; editing uses slide-over
panels, preserving the existing validation, approval controls and permissions.
