# Canvas AI Disclosure

The Image component reads `image.aiDisclosure` from the selected Media entity,
not a separate Canvas control. The module extends Canvas's image field type and
Media image expressions with a computed property. No Media IDs or file URLs are
sent to a second API for disclosure lookup.

Enable with `ddev drush en canvas_ai_disclosure -y`, then sync the **entire**
frontend component directory with Canvas CLI. Do not push a single component
directory: the CLI treats missing components as deletions.

The install hook extends all existing component version mappings so previously
saved pages continue to work. To reapply that idempotent mapping update:

```sh
ddev drush php:eval '\Drupal\canvas_ai_disclosure\MappingUpdater::updateExistingComponents();'
```

The resolver runs on the Media translation that owns the image field, honoring
profiles and inheritance. Its cache metadata is carried through Canvas into the
headless response, including when no disclosure resolves. Field view access is
respected. EU icon `ai_generated` maps to `AI generated`, and `ai_modified`/`ai`
map to `AI assisted`; `none` produces no badge. These badges report AI
involvement independently of the module's legal-obligation visibility policy.

The integration uses Canvas internal PHP expression classes. Verify it when
updating Canvas. It preserves the standard image schema and adds an optional
computed property to its output. Other components can opt into the same value.

