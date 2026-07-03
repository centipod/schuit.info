You are developing a modern genealogy visitor portal for the Schuit family history project.

The existing situation:

* The genealogy source of truth is Aldfaer.
* Aldfaer remains the master system for maintaining genealogy data.
* webtrees remains the online genealogy browser.
* webtrees will receive periodic GEDCOM imports exported from Aldfaer.
* The project currently has a WordPress website and a webtrees installation.
* We have exports from the WordPress database and webtrees database.
* We have a copy of the WordPress installation, including uploaded files, images and media.
* We can provide the latest GEDCOM export from Aldfaer.
* The existing public site at https://schuit.info may be used as functional and content inspiration, but the new version must be modernised significantly.
* The repository root already contains `logo.png` and `banner.png`; use them as the initial shared brand assets for the website and webtrees integration.
* Final refinements to look and feel can be provided separately, but the design should already work with the root assets in place.

Core architectural decisions:

* Never use iframes.
* Keep WordPress and webtrees technically separate.
* Keep separate databases for WordPress and webtrees.
* Do not merge the WordPress and webtrees databases.
* Do not make WordPress the genealogy data store.
* Do not make webtrees the general content management system.
* Do not let WordPress directly edit genealogy records.
* Do not duplicate structured genealogy facts manually in WordPress.
* Do not bypass webtrees privacy/access rules.
* Use WordPress as the public visitor portal unless a clearly better alternative is justified.
* Use webtrees as the genealogy browsing application.
* Host webtrees under a clean route such as /tree/.
* Use deep links from the portal into webtrees for individuals, families, charts and branches.
* Use visual/theming integration so visitors experience the site as one coherent portal.
* Admin users are limited to a small group.
* Only authorised users may access WordPress admin, webtrees admin, and the secure GEDCOM import form.
* The solution must be cost-effective to run on AWS.

Primary goal:

Build a modern public genealogy portal where visitors can read curated family history content, browse family branches, view stories and images, and then move naturally into webtrees to explore the GEDCOM-based genealogy data.

The visitor should experience the site as one coherent website, even though WordPress and webtrees remain separate applications.

Target structure:

/
  Modern public portal, preferably WordPress unless migration to another CMS is justified.
/tree/
  webtrees genealogy browser using imported GEDCOM data.
/admin/ or /wp-admin/
  WordPress admin for authorised content editors only.
Secure GEDCOM import area
  Available only to authorised users.
  Allows upload of a new GEDCOM file.
  Validates and stages the GEDCOM.
  Backs up current webtrees state.
  Imports the GEDCOM into webtrees.
  Logs the import result.

Functional requirements:

1. Public portal

Create a modern visitor-facing portal with at least the following areas:

* Home
* Family Tree
* Family Branches
* Stories
* Photos or Archive
* Sources and Research
* About the Project
* Contact
* Privacy

The homepage should provide a welcoming entry point into the family history project. It should explain what the site contains, guide visitors to major family branches, and provide a clear route into the webtrees tree browser.

The “Family Tree” section should explain that the structured genealogy tree is browsed in webtrees and provide clear calls to action into /tree/.

The “Family Branches” section should contain curated branch landing pages. Each branch page may include:

* Short introduction
* Historical context
* Important people
* Relevant places
* Selected images
* Links into webtrees
* “Explore this branch in the family tree” button

The “Stories” section should contain article-style family history content migrated from the existing WordPress site where relevant.

The “Photos / Archive” section should provide curated access to uploaded images, documents or media from the existing WordPress uploads where appropriate.

2. webtrees integration

Install or configure webtrees as the online genealogy browser under /tree/.

The integration must be route-based and visual, not iframe-based.

Required integration points:

* Shared logo
* Shared color palette
* Shared typography where practical
* Shared top navigation or visually compatible navigation
* Shared footer style
* Portal links from webtrees back to the main site
* WordPress portal links into relevant webtrees pages
* Clean “Explore in tree” buttons on portal pages
* Optional custom webtrees theme or module if needed

The webtrees application should retain its own authentication, authorisation, database and privacy model unless an explicit later decision is made to add SSO.

Do not expose private genealogy data outside webtrees.

3. GEDCOM upload and import

Build a secure GEDCOM upload and import workflow.

This is required because selected users need the ability to upload a new GEDCOM file and trigger an import into webtrees without manually using server access.

The import workflow should be designed as follows:

Authorised user logs in
  ↓
Opens secure GEDCOM import page
  ↓
Uploads `.ged` file
  ↓
System validates file type, size and basic GEDCOM structure
  ↓
System stores uploaded GEDCOM in a staging area
  ↓
System creates backup of current webtrees database and relevant media/config state
  ↓
System imports or updates webtrees tree data
  ↓
System records logs and status
  ↓
User receives success/failure result

Security requirements for GEDCOM upload:

* Only authenticated and authorised users may access the form.
* Do not expose the upload form publicly.
* Accept only .ged files.
* Enforce sensible file size limits.
* Store uploaded files outside the public web root.
* Use CSRF protection.
* Use server-side validation.
* Log who uploaded the file, when, filename, size, checksum and result.
* Retain a limited number of previous uploaded GEDCOM files for rollback/audit.
* Back up the webtrees database before every import.
* Do not overwrite backups silently.
* Provide clear failure messages without leaking sensitive server details.
* Do not allow arbitrary file upload or execution.
* Ensure imports cannot be triggered repeatedly by accident.
* Consider a confirmation step before final import.

Implementation options for GEDCOM import:

Preferred:

* Use webtrees-supported mechanisms where available.
* If CLI or admin import tooling is available and stable, call it from a controlled backend job.
* If the import must be automated through database/application logic, keep this isolated and document upgrade risks.

Alternative:

* Build the secure upload form as a small standalone admin utility outside WordPress and outside webtrees, protected by authentication.
* This utility may share the hosting environment but must not merge databases.

Do not implement GEDCOM upload as a public WordPress media upload.

4. Content migration

Migrate useful content from the existing WordPress site.

Inputs:

* WordPress database export
* WordPress installation files
* wp-content/uploads
* Existing pages/posts/categories/tags
* Existing images and documents

Tasks:

* Inventory existing pages and posts.
* Identify which content should be kept, rewritten, archived or discarded.
* Preserve important content and media.
* Preserve or redirect important URLs where possible.
* Clean up obsolete plugins, shortcodes and theme-specific markup.
* Avoid carrying over unnecessary WordPress bloat.
* Rebuild content into modern templates.
* Optimise images.
* Ensure accessibility basics are met.

If a non-WordPress portal is proposed, provide a clear justification and migration path. The default remains WordPress because it provides familiar editing for a small group of administrators.

5. Data separation

Maintain clear system boundaries:

Aldfaer
  Master genealogy database
  Human genealogy editing happens here
GEDCOM export
  Transfer format from Aldfaer to webtrees
webtrees database
  Online genealogy browser data
  Imported from GEDCOM
  Own users/permissions/privacy rules
WordPress database
  Portal content only
  Pages, posts, media references, navigation, editorial content

Do not create cross-database dependencies that make upgrades fragile.

If portal pages need to reference webtrees records, use stable webtrees URLs or explicit manually managed reference fields.

6. Privacy

Genealogy privacy is critical.

Implement and verify:

* Living people must not be publicly exposed unless explicitly allowed.
* webtrees privacy settings must be configured and tested.
* GEDCOM imports must not accidentally reset privacy assumptions.
* Media visibility must be reviewed.
* Backups must not be publicly accessible.
* Uploaded GEDCOM files must not be publicly downloadable.
* Admin areas must be protected.
* Use strong passwords and MFA where possible.
* Use HTTPS everywhere.
* Keep WordPress, plugins, themes, PHP and webtrees updated.

7. AWS deployment

Deploy cost-effectively on AWS.

Production domain:

* stichtingschu-y-i-ij-t.nl
* Use Route 53 for DNS and create a hosted zone for the domain.
* After the hosted zone is created, copy the four Route 53 NS records into the registrar.
* The exact NS values are assigned by AWS when the hosted zone is created.

Preferred initial deployment:

Amazon Lightsail or small EC2 instance
  Runs:
    - WordPress
    - webtrees
    - PHP runtime
    - Nginx or Apache
    - MySQL/MariaDB, unless managed DB is selected
S3
  - database backups
  - GEDCOM upload archive
  - WordPress media backup
  - webtrees backup copies
Route 53
  - DNS for stichtingschu-y-i-ij-t.nl
  - Hosted zone NS records to be published at the registrar
HTTPS
  - Let’s Encrypt or AWS-managed certificate where appropriate
Optional CloudFront
  - CDN/caching for public assets

The first version should favour simplicity and low monthly cost.

Avoid over-engineering with ECS/EKS/serverless unless there is a clear operational benefit.

Use Infrastructure as Code if practical, preferably Terraform.

Provide a backup and restore procedure.

8. Hosting layout

Recommended server layout:

/var/www/portal
  WordPress codebase
/var/www/webtrees
  webtrees codebase
/var/private/gedcom-staging
  GEDCOM uploads, not publicly accessible
/var/backups/genealogy
  temporary local backup location before sync to S3

Recommended routing:

https://stichtingschu-y-i-ij-t.nl/
  WordPress portal
https://stichtingschu-y-i-ij-t.nl/tree/
  webtrees
https://stichtingschu-y-i-ij-t.nl/wp-admin/
  WordPress admin, restricted
https://stichtingschu-y-i-ij-t.nl/gedcom-import/
  secure GEDCOM upload/import area, restricted

The exact routes may be adjusted, but webtrees should remain under a clean route such as /tree/.

9. Security hardening

Minimum hardening:

* HTTPS only.
* Redirect HTTP to HTTPS.
* Restrict admin login routes where practical.
* Use least-privilege database users.
* Use separate database users for WordPress and webtrees.
* Keep separate databases.
* Disable direct PHP execution in upload directories.
* Protect staging and backup directories from web access.
* Add automated backups.
* Add basic monitoring.
* Add fail2ban or equivalent login protection if suitable.
* Use security headers where compatible.
* Keep a patching procedure.
* Document admin users and roles.

10. Visual design

The repository root already contains `logo.png` and `banner.png`. Use these as the starting brand assets for the public portal and the genealogy app.

Design the system to support:

* Modern, clean, heritage-oriented visual style
* Strong readability
* Warm but not old-fashioned presentation
* Large photographic/header areas
* Good mobile support
* Branch cards
* Story cards
* Clear calls to action
* Calm navigation
* Accessible contrast
* Responsive layouts
* Integration with webtrees styling

Design foundation:

* Treat `banner.png` as the default hero/background image until a replacement is supplied.
* Treat `logo.png` as the shared mark for the portal header, webtrees integration points, and navigation.
* Derive the color palette, button treatment, card style, and section spacing from these assets so the website and app feel like one system.
* Keep the theme modular so the visual direction can be refreshed without rewriting content or import logic.

Do not copy the existing schuit.info design directly. Use it only for understanding content, structure and intent. The new portal must feel modern.

11. Accessibility and UX

Implement:

* Responsive design
* Keyboard navigability
* Proper heading hierarchy
* Good contrast
* Alt text support for images
* Clear link labels
* No iframe navigation traps
* Useful empty states
* Clear error pages
* Clear privacy/contact pages

12. Performance

Implement:

* Image optimisation
* Caching for public WordPress pages
* PHP opcache
* Database tuning appropriate to small VPS hosting
* CDN only if it adds value
* Avoid heavy WordPress plugin stacks
* Avoid unnecessary page builders unless specifically approved
* Keep the theme lean

13. Admin roles

There are only a few admin users.

Suggested roles:

Portal administrator
  Manages WordPress settings, users, theme and content.
Portal editor
  Edits WordPress pages/posts/media.
webtrees administrator
  Manages webtrees settings, tree privacy, users and import verification.
GEDCOM importer
  Can upload GEDCOM and trigger import, but does not necessarily have full server access.

The GEDCOM importer role should be explicit and auditable.

14. Deliverables

Produce the following:

* Target architecture
* AWS deployment design
* Content migration plan
* WordPress theme or selected theme approach
* webtrees theme/customisation approach
* GEDCOM upload/import design
* Backup and restore plan
* Security/privacy checklist
* Implementation backlog
* Deployment scripts or documented deployment steps
* Admin documentation
* User guide for GEDCOM upload/import
* Rollback procedure

15. Implementation backlog

Suggested backlog:

Epic 1 — Discovery and inventory
  - Inspect WordPress export
  - Inspect WordPress uploads
  - Inspect webtrees export
  - Inspect latest GEDCOM
  - Inventory existing URLs
  - Inventory existing content
  - Identify content to migrate
Epic 2 — AWS foundation
  - Provision Lightsail or EC2
  - Configure DNS
  - Configure HTTPS
  - Configure PHP/Nginx or Apache
  - Configure MySQL/MariaDB
  - Configure S3 backups
  - Document restore process
Epic 3 — WordPress portal
  - Install clean WordPress
  - Build or configure modern theme
  - Create content types/templates if needed
  - Migrate pages/posts
  - Migrate media
  - Configure navigation
  - Configure caching/security basics
Epic 4 — webtrees setup
  - Install webtrees under `/tree/`
  - Configure database
  - Import GEDCOM
  - Configure privacy rules
  - Configure users/roles
  - Apply visual integration
  - Add portal navigation links
Epic 5 — GEDCOM import workflow
  - Build secure upload form
  - Add authorisation
  - Validate GEDCOM upload
  - Store staged uploads securely
  - Back up current webtrees state
  - Trigger import/update
  - Log import result
  - Add rollback documentation
Epic 6 — Integration polish
  - Add deep links from portal to webtrees
  - Add branch landing pages
  - Add “Explore in tree” components
  - Align header/footer styling
  - Test mobile navigation
  - Test privacy boundaries
Epic 7 — Launch
  - Final content QA
  - Final privacy QA
  - Final backup/restore test
  - DNS cutover
  - Monitor logs
  - Document admin process

16. Acceptance criteria

The solution is acceptable when:

* Public visitors can browse the modern portal.
* Public visitors can access webtrees under /tree/.
* WordPress and webtrees feel visually connected.
* No iframe is used.
* WordPress and webtrees keep separate databases.
* webtrees remains the genealogy browser.
* Aldfaer remains the master source.
* A GEDCOM exported from Aldfaer can be securely uploaded by an authorised user.
* A GEDCOM import creates a backup before modifying webtrees data.
* GEDCOM upload and import actions are logged.
* Private GEDCOM files and backups are not publicly accessible.
* Living/private people are not unintentionally exposed.
* WordPress content and media from the old site are migrated or intentionally archived.
* Admin access is restricted to authorised users.
* The site runs cost-effectively on AWS.
* There is a documented backup and restore process.
* There is a documented rollback process after failed GEDCOM import.
* The implementation is maintainable by a small admin group.

17. Important non-goals

Do not:

* Build a genealogy engine inside WordPress.
* Replace webtrees with a WordPress genealogy plugin.
* Use iframes.
* Merge WordPress and webtrees databases.
* Make WordPress directly read or write webtrees internals unless explicitly approved.
* Create a fragile integration that breaks on webtrees upgrades.
* Expose GEDCOM uploads publicly.
* Treat the WordPress media library as the GEDCOM upload mechanism.
* Over-engineer infrastructure beyond the project’s small-user, cost-sensitive requirements.

18. Preferred final architecture summary

The final solution should be:

Aldfaer
  ↓ manual or scheduled GEDCOM export
Secure GEDCOM upload/import workflow
  ↓ validates, backs up, imports, logs
webtrees at /tree/
  ↓ structured genealogy browsing
WordPress portal at /
  ↓ stories, branches, photos, context, navigation
AWS low-cost hosting
  ↓ Lightsail/EC2 + MySQL/MariaDB + S3 backups + HTTPS + DNS

The result should be a modern genealogy website that feels unified to visitors while remaining technically clean, secure and maintainable.

Replace the previous AWS infrastructure section with the following final v1 infrastructure design.

AWS infrastructure design

The solution will run on Amazon Lightsail for v1.

The website is low-volume and cost-sensitive. Therefore, the architecture must remain simple, predictable and easy to operate.

Do not use Fargate, ECS, EKS, RDS, ALB, WAF, EFS or CloudFront in v1 unless a specific later requirement justifies it.

The v1 infrastructure is:

Route 53 or existing DNS
  ↓
Amazon Lightsail static IP
  ↓
Amazon Lightsail Linux instance
  - Nginx or Apache
  - PHP
  - MariaDB/MySQL
  - WordPress at /
  - webtrees at /tree/
  - secure GEDCOM import utility
  ↓
Private S3 bucket
  - database backups
  - GEDCOM upload archive
  - WordPress uploads backup
  - webtrees backup copies

The current Lightsail Linux bundles with public IPv4 start at $5/month for 0.5 GB memory and $7/month for 1 GB memory, with larger bundles available if needed. For this project, start with the smallest practical bundle and resize upward only if performance requires it. The likely starting point is the 1 GB bundle rather than the 0.5 GB bundle, because WordPress, webtrees, PHP and MariaDB will all run on the same instance. (docs.aws.amazon.com)

Infrastructure as Code

All AWS infrastructure must be provisioned using Terraform.

No manual AWS console setup should be required after the first bootstrap, except for one-off account/domain actions that cannot reasonably be automated.

Terraform must manage:

Lightsail instance
Lightsail static IP
Static IP attachment
Lightsail firewall ports
S3 backup bucket
S3 bucket encryption
S3 public-access block
S3 lifecycle rules
IAM user/policy or role credentials for backup access
Route 53 hosted zone, if DNS is managed in AWS
Route 53 DNS records, if DNS is managed in AWS

Terraform should not manage application content, WordPress posts, uploaded media, GEDCOM files or database content.

Terraform project structure

Use a simple Terraform structure:

infra/
  terraform/
    environments/
      prod/
        main.tf
        variables.tf
        outputs.tf
        terraform.tfvars.example
    modules/
      lightsail-web/
        main.tf
        variables.tf
        outputs.tf
      backup-bucket/
        main.tf
        variables.tf
        outputs.tf
      dns/
        main.tf
        variables.tf
        outputs.tf

For a small project, environments can remain minimal. Do not introduce unnecessary multi-environment complexity unless staging is required.

Terraform state

Use remote Terraform state.

Preferred:

S3 bucket for Terraform state
DynamoDB table for state locking, if using standard Terraform backend patterns

However, because this is a small Lightsail project, local state may be acceptable during the earliest prototype only. Production infrastructure must use remote state before launch.

Terraform state must not be committed to Git.

Lightsail instance

Provision one Lightsail Linux instance.

Recommended initial size:

Micro / 1 GB Lightsail Linux bundle

Reason:

* WordPress and webtrees both need PHP.
* MariaDB/MySQL runs locally.
* GEDCOM imports may temporarily need memory.
* 0.5 GB may be too tight once WordPress plugins, PHP-FPM and database are active.

Use the smallest stable configuration first. If performance is poor, resize to the next Lightsail bundle before considering a different AWS architecture.

The instance should run:

Ubuntu LTS or Debian stable
Nginx as the web server and reverse proxy
PHP supported by current WordPress and webtrees versions
MariaDB/MySQL
Certbot / Let’s Encrypt for TLS certificates
AWS CLI or compatible S3 sync tooling

Static IP

Terraform must allocate a Lightsail static IP and attach it to the Lightsail instance.

The domain must point to this static IP. AWS documents the standard Lightsail pattern as creating a static IP, attaching it to the instance, and pointing the domain’s DNS record at that static IP. (docs.aws.amazon.com)

DNS

Preferred DNS provider:

Route 53

If the domain must remain with another DNS provider, Terraform should still manage everything else and DNS records should be documented manually.

Canonical domain:

https://schuit.info

Cutover note:

* `schuit.info` currently points to the existing server.
* Do not change DNS until the Lightsail stack, TLS, imports and backups are all verified.
* Prepare the Lightsail host first, then switch the domain only during the final cutover.

Redirect:

https://www.schuit.info
  → https://schuit.info

Required records if Route 53 is used:

A record:
  schuit.info → Lightsail static IP
CNAME or A record:
  www.schuit.info → schuit.info or Lightsail static IP

If existing mail uses the domain, preserve all existing mail records:

MX
SPF
DKIM
DMARC

Do not overwrite mail-related DNS records.

Application routing

The server must route applications as follows:

https://schuit.info/
  WordPress public portal
https://schuit.info/tree/
  webtrees genealogy browser
https://schuit.info/wp-admin/
  WordPress admin
https://schuit.info/gedcom-import/
  secure GEDCOM upload/import utility

Rules:

* No iframes.
* WordPress and webtrees remain separate applications.
* WordPress and webtrees use separate databases.
* webtrees is served under /tree/.
* GEDCOM import is not a WordPress media upload.
* GEDCOM upload/import is restricted to authorised users.

Server filesystem layout

Use a release-based deployment layout:

/var/www/releases/
  202607010001/
  202607010002/
/var/www/current
  symlink to active release
/var/www/shared/
  wordpress/
    wp-config.php
    wp-content/uploads/
  webtrees/
    data/
    media/
    config/
  env/
    portal.env
/var/private/gedcom-staging/
  temporary GEDCOM uploads, not public
/var/private/gedcom-archive/
  retained GEDCOM uploads, not public
/var/backups/genealogy/
  local temporary backups before sync to S3
/opt/genealogy/
  deploy scripts
  backup scripts
  import scripts
  maintenance scripts

The web server should serve from:

/var/www/current/wordpress
/var/www/current/webtrees

Mutable runtime files must live under /var/www/shared or /var/private, not inside release directories.

Database layout

Use local MariaDB/MySQL on the Lightsail instance.

Create separate databases:

wordpress_db
webtrees_db

Create separate database users:

wordpress_user
  access only to wordpress_db
webtrees_user
  access only to webtrees_db

Do not share database credentials.

Do not allow WordPress to read or write the webtrees database.

Do not allow webtrees to read or write the WordPress database.

S3 backups

Terraform must create a private S3 backup bucket.

The bucket must have:

block public access enabled
server-side encryption enabled
versioning enabled if cost is acceptable
lifecycle rules
least-privilege IAM access

Suggested S3 structure:

s3://<backup-bucket>/
  wordpress/
    database/
    uploads/
  webtrees/
    database/
    data/
    media/
  gedcom/
    uploads/
    import-logs/
  server/
    config/
    deploy-logs/
  terraform/
    state/   # only if same account/state design permits this

GEDCOM files, database dumps and backups must never be publicly accessible.

Backup schedule

Automate backups from the Lightsail instance.

Minimum schedule:

Daily:
  WordPress database dump
  webtrees database dump
Daily or weekly, depending on size:
  WordPress uploads sync
  webtrees media/data sync
Before every GEDCOM import:
  webtrees database backup
  webtrees data/config backup
  uploaded GEDCOM archived
  import log written

Suggested retention:

Daily backups:
  14–30 days
Weekly backups:
  8–12 weeks
Monthly backups:
  6–12 months
Pre-import backups:
  keep at least the latest 10 imports

Retention must be implemented using S3 lifecycle rules where practical.

HTTPS

Use Let’s Encrypt on the Lightsail instance.

Requirements:

HTTP redirects to HTTPS
Certificates renew automatically
Renewal is tested
Canonical domain is enforced
www redirects to non-www

Do not use ACM/CloudFront in v1 unless CloudFront is introduced later.

Firewall

Configure Lightsail networking/firewall to allow only:

80/tcp
  HTTP, redirect to HTTPS
443/tcp
  HTTPS
22/tcp
  SSH, restricted where practical

Database ports must not be publicly exposed.

MariaDB/MySQL should bind to localhost.

GitHub CI/CD

Use GitHub Actions for application deployment.

Deployment model:

Push to main
  ↓
GitHub Actions builds/packages release
  ↓
GitHub Actions SSHs into Lightsail
  ↓
Server creates pre-deploy backup
  ↓
Release is extracted to /var/www/releases/<timestamp>
  ↓
Shared files are symlinked
  ↓
/var/www/current symlink is switched
  ↓
PHP/web server reloads
  ↓
Old releases are retained for rollback

GitHub Actions should deploy:

WordPress custom theme
WordPress custom plugins
webtrees custom theme/module/customisation
GEDCOM import utility
server-side deploy scripts, if changed deliberately

GitHub Actions must not deploy:

WordPress uploads
webtrees media
GEDCOM files
database dumps
secrets
private keys

Secrets

Do not commit secrets to Git.

Manage secrets using:

GitHub Actions secrets for deployment SSH key and deployment variables
server-side env files with strict permissions for runtime secrets
AWS IAM credentials with least privilege for S3 backups

For this v1 Lightsail design, AWS Secrets Manager is optional and probably unnecessary.

GEDCOM import utility

The GEDCOM import utility runs on the Lightsail instance.

It must:

authenticate authorised user
accept only .ged files
validate file size and basic GEDCOM structure
store upload outside the public web root
calculate checksum
create pre-import webtrees backup
archive uploaded GEDCOM to S3
trigger webtrees import/update process
log import result
show clear success/failure status
support rollback using the pre-import backup

The import utility must not use WordPress media uploads.

The import utility must not expose GEDCOM files publicly.

The import utility must not merge WordPress and webtrees data.

Monitoring and maintenance

Keep v1 simple.

Implement:

system logs
Nginx/Apache logs
PHP logs
MariaDB logs
backup logs
GEDCOM import logs
basic disk-space monitoring
basic uptime check

CloudWatch agent is optional. Do not overbuild monitoring for v1.

Restore and rollback

Document and test:

restore WordPress database
restore WordPress uploads
restore webtrees database
restore webtrees data/media
rollback a failed GEDCOM import
rollback to previous application release
rebuild server from Terraform + backups

Acceptance criterion:

Before launch, perform at least one test restore in staging or on a temporary Lightsail instance.

Non-goals for v1

Do not implement:

Fargate
ECS
EKS
RDS
ALB
CloudFront
WAF
EFS
multi-AZ
autoscaling
container orchestration
database replication
blue/green infrastructure
static S3 hosting for WordPress or webtrees

These can be reconsidered later only if traffic, security, editorial workflow or operational needs justify them.

Final v1 architecture

Aldfaer
  ↓ GEDCOM export
Secure GEDCOM upload/import utility on Lightsail
  ↓ validate, backup, import, log
webtrees at /tree/
  ↓ structured genealogy browser
WordPress at /
  ↓ modern family-history portal
Local MariaDB/MySQL
  ↓ separate wordpress_db and webtrees_db
S3
  ↓ private backups, GEDCOM archive, restore source
Terraform
  ↓ provisions AWS infrastructure
GitHub Actions
  ↓ deploys application code to Lightsail