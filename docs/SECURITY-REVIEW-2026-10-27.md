# SCHUIT maintenance release — 27 October 2026

WordPress has been updated to 7.1.3. Webtrees keeps version 2.2.6 with a
reproducible security dependency overlay. The shared site identity now includes
its logo, navigation, typography and colour palette, with responsive menus and
native genealogy chart styling. A functional session cookie avoids Webtrees'
first-visit reload when navigating from the portal; its bot guard remains intact.

Validation covered HTTPS behaviour, both sign-in flows, CSRF/logout, genealogy
privacy, GEDCOM parsing and transactional import, content/asset availability,
desktop/mobile navigation and backup restore. Existing genealogy is preserved.

The detailed security findings, deployed runtime inventory and acceptance
evidence are maintained in the owner's private infrastructure repository.
