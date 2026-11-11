# Two exposed slots in a shared Canvas template

This prototype uses a Drupal node bundle, `slot_demo`, with one shared Canvas
Content Template. It is not a standalone `canvas_page` entity.

## Open the demo

- Next frontend: https://d11-next.ddev.site:3000/slot-demo/alpha
- Second page: https://d11-next.ddev.site:3000/slot-demo/beta
- Canvas editor, Alpha: https://d11-next.ddev.site/canvas/editor/node/1
- Canvas editor, Beta: https://d11-next.ddev.site/canvas/editor/node/2
- Content Templates: open Canvas and select the template for **Two-slot editorial page**.

Node IDs above are from this database. The setup script prints the actual editor
URLs when creating the demo on another database.

The `Editorial template` React component supplies the shared header, hero,
responsive two-column layout, and footer. Its title reads the node's title.
Its `main` and `supplementary` slots are exposed through two separate
`component_tree` fields:

| Slot | Node field | Editor label |
| --- | --- | --- |
| `main` | `canvas_slot_main` | Main content |
| `supplementary` | `canvas_slot_additional` | Additional content |

Each node stores its own components in each field. The shared template has one
shell component; slot editing never copies that shell into the node.

## Roles

- **Slot demo: moderator** (`slot_demo_editor`) can create/edit this content type,
  preview drafts in Next, edit both slots, and publish. It cannot administer
  Content Templates.
- **Slot demo: template designer** (`slot_demo_designer`) has the same permissions
  plus `administer content templates`. That permission applies site-wide.

Assign one of these roles to a test account through Drupal's People screen.
No persistent test account or password is included. The integration check creates
and removes its own temporary moderator account and node.

The moderator can edit page metadata, including the title. Slot component allowlists
and component availability by role are separate features and are not configured
by this prototype.

## Experimental dependency

Canvas is pinned to commit `0d25ce99b2fc4e3d94a722013bb867183e046e5e`
from **draft MR !1359**, using a Composer package repository and immutable archive
URL. `dev-exposed-slots as 1.12.0` is a Composer compatibility alias, not a claim
that this is the official Canvas 1.12.0 release. Do not treat this development
snapshot as a production-ready release.

- [Canvas issue #3591817: exposed slots](https://www.drupal.org/project/canvas/issues/3591817)
- [MR !1359: implementation used here](https://git.drupalcode.org/project/canvas/-/merge_requests/1359)
- [Issue #3526189: one Canvas field per exposed slot](https://www.drupal.org/project/canvas/issues/3526189)

Drupal was updated from 11.4.6 to 11.4.11. Next remains at 16.2.12; the existing
Canvas Headless SDK handles the tested draft and publication flows.

## Reproduce on this project

The source archive has no compiled Canvas editor UI. Build it after Composer
installs or reinstalls the experimental package:

```bash
ddev composer install
bash scripts/build-canvas-prototype.sh
ddev drush updatedb -y
ddev exec -d /var/www/html/frontend npm ci
ddev exec -d /var/www/html/frontend npx canvas push -y --no-pages --no-content-templates --no-regions
ddev drush php:script scripts/setup-slot-demo.php
ddev drush cache:rebuild
```

The setup script creates missing demo configuration and pages, grants the demo
roles' permissions, and registers the shell with the existing Next frontend.
Re-running it preserves existing demo slot content and template changes.
The committed configuration is also available for the project's normal config
import workflow. The demo nodes themselves are seeded by the setup script,
not stored in configuration exports.

After intentional changes to demo configuration, export only the demo-owned
configuration rather than unrelated database configuration:

```bash
ddev drush php:script scripts/export-slot-demo.php
```

## Verification

```bash
ddev exec -d /var/www/html/frontend npm run check
ddev drush php:script scripts/check-slot-demo.php
```

The integration check uses HTTP requests with an authenticated moderator session.
It checks two editable regions, template access denial, rejection of writes
outside the slots, independent auto-save, Next draft rendering, publication of
both fields, Next rendering of both published pages, and propagation of a shared
template change. It restores the temporarily changed template and deletes its
test node and account in a `finally` block. Run it on the seeded local demo,
without concurrent editing; it expects the initial example headings.

Interactive browser verification was unavailable because local browser use is
disabled in the Codex Work settings. Drag-and-drop interaction and viewport
appearance still need a manual check in Canvas. The HTTP and lint/type checks
passed.

## Recovery

Before updating dependencies, the local database was backed up to
`private/backups/before-exposed-slots.sql.gz` (ignored by Git). Earlier unrelated
working-tree changes are preserved in the stash named
`Before two exposed slots prototype 2026-11-04`.

To return to the previous state, restore the previous Composer files and install
those dependencies together with the database backup. Reverting only the code
would leave the new exposed-slot configuration on the old storage contract.
