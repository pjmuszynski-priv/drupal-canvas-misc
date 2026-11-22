# Canvas slot component restrictions

The project applies a patch on top of the pinned Exposed Slots snapshot of Canvas.
Declare permitted Canvas component IDs in a component's slot metadata:

```yaml
slots:
  items:
    title: Tabs
    expected:
      - js.tab-item
```

An absent or empty `expected` list leaves the slot unrestricted. This proof of
concept accepts explicit Canvas IDs such as `js.tab-item` or `sdc.theme.component`.
Changing the list updates the component version. The active policy applies to
older instances too, so an old version ID cannot bypass the restriction.

The editor filters the library for the selected slot and rejects
structural changes that introduce invalid children. Hovering during a drag
does not refilter the library, keeping the active drag source mounted. The same guard covers
dragging, moving, pasting, patterns, duplication, and undo/redo. It checks the
whole candidate tree before modifying state. Existing invalid content can be
loaded and removed or moved to a compatible slot.

For Exposed Slots, the Layout API resolves the template-owned slot and sends
its policy with the editable region. Roots of the node's backing field must
satisfy that policy. Empty overrides and reverting to the template default
remain supported. The active content-template draft supplies the policy while
editing; saved content uses the published template.

Drupal checks restrictions during entity validation, direct entity saves, and
auto-save. A rejected Layout API request returns 422 and preserves stored
content and auto-save hashes. Other incomplete draft inputs retain Canvas's
existing behavior. Tightening a policy may require repairing existing content
before its next save or publication.

## Install and rebuild

Composer's post-install and post-update scripts apply
`patches/canvas-slot-restrictions.patch` idempotently. Build the patched editor:

```bash
ddev composer install
bash scripts/build-canvas-prototype.sh
```

The build script also applies the patch, making it usable on an already
installed checkout. Component metadata must then be synchronized from Next
through Canvas's frontend component sync or the project's normal Canvas push.

## Verify

```bash
ddev drush php:script scripts/check-slot-restrictions.php
ddev drush php:script scripts/check-slot-demo.php
ddev exec -d /var/www/html/web/modules/contrib/canvas npm run test --workspace=@drupal-canvas/ui -- src/features/layout/exposedSlots.test.jsx src/components/list/ComponentList.test.tsx
```

The first check creates a temporary content type, template, node, role, and
account. It tests regular and exposed slots through entity validation and the
authenticated Layout API, then removes its fixtures. The second checks the
existing two-slot demo, including Next rendering.
