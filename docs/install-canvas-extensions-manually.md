# Ręczna instalacja Exposed Slots i ograniczeń slotów

Instrukcja dotyczy czystego projektu Drupal + Canvas + Next. Kod Canvas pobierasz
z Gita Drupala. Zmiany ograniczeń przepisujesz ręcznie. Zakłada możliwość
instalowania zależności Composer/npm oraz budowania edytora Canvas.

## 1. Przygotuj bazę z Exposed Slots

Używamy snapshotu MR !1359, commit
`0d25ce99b2fc4e3d94a722013bb867183e046e5e`, z repozytorium
`https://git.drupalcode.org/issue/canvas-3591817.git`.

W composer.json dodaj poniższy wpis jako pierwszy element repositories,
przed repozytorium packages.drupal.org. Jeżeli projekt ma już inne repozytoria,
zachowaj je. To deklaracja pakietu z przypiętym kodem źródłowym.

```json
{
  "type": "package",
  "package": {
    "name": "drupal/canvas",
    "version": "dev-exposed-slots",
    "type": "drupal-module",
    "license": "GPL-2.0-or-later",
    "description": "Canvas exposed slots prototype — MR !1359, pinned commit",
    "require": {
      "drupal/core": "^11.3",
      "justinrainbow/json-schema": "^6.8.0"
    },
    "dist": {
      "type": "zip",
      "url": "https://git.drupalcode.org/api/v4/projects/235995/repository/archive.zip?sha=0d25ce99b2fc4e3d94a722013bb867183e046e5e",
      "reference": "0d25ce99b2fc4e3d94a722013bb867183e046e5e"
    },
    "source": {
      "type": "git",
      "url": "https://git.drupalcode.org/issue/canvas-3591817.git",
      "reference": "0d25ce99b2fc4e3d94a722013bb867183e046e5e"
    }
  }
}
```

Ustaw wymaganie pakietu:

```bash
composer require 'drupal/canvas:dev-exposed-slots as 1.12.0' --with-all-dependencies
```

W projekcie DDEV dodawaj przed poleceniami composer, php i drush odpowiednie
`ddev composer`, `ddev php` i `ddev drush`. Wykonuj polecenia buildu w kontenerze,
jeśli z niego korzystasz.

Snapshot wymaga Drupal Core ^11.3. Zachowaj zgodny zestaw modułów i SDK
headless w nowym projekcie. Alias 1.12.0 służy rozwiązywaniu zależności.

Po zmianie zależności uruchom aktualizacje bazy:

```bash
drush updatedb -y
```

## 2. Przepisz ograniczenia do kodu Canvas

Poniższe ścieżki są względne wobec web/modules/contrib/canvas.
Każda sekcja zawiera zmiany względem powyższego snapshotu Exposed Slots.
W diffach linie z minusem usuwasz, linie z plusem dodajesz. Linie bez tych
prefiksów pokazują kontekst. Nagłówki @@ wskazują orientacyjne miejsca.

Nowe pliki oznaczono osobno i pokazano ich pełną zawartość. Zmiany obejmują
zachowanie aktywnego elementu podczas przeciągania, obsługę Exposed Slots,
wersjonowanie reguł, walidację API, autozapisu i bezpośredniego zapisu encji.

### 2.1. `config/schema/canvas.schema.yml`

```diff
diff --git a/config/schema/canvas.schema.yml b/config/schema/canvas.schema.yml
--- a/config/schema/canvas.schema.yml
+++ b/config/schema/canvas.schema.yml
@@ -533,6 +533,16 @@
       sequence:
         type: text
         label: 'Example for slot'
+    expected:
+      type: sequence
+      label: 'Expected component IDs'
+      requiredKey: false
+      sequence:
+        type: string
+        constraints:
+          Regex:
+            pattern: '/^[a-z][a-z0-9_]*\.[a-zA-Z0-9_.:-]+$/'
+        label: 'Canvas component ID'
 
 canvas.component_source_local_id.sdc:
   type: canvas.component_source_local_id.*
```

### 2.2. `openapi.yml`

```diff
diff --git a/openapi.yml b/openapi.yml
--- a/openapi.yml
+++ b/openapi.yml
@@ -4588,6 +4588,8 @@
                   description:
                     type: string
                     title: Description
+                  expected:
+                    $ref: '#/components/schemas/ExpectedSlotComponents'
                   examples:
                     type: array
                     items:
@@ -4692,6 +4694,8 @@
               description:
                 type: string
                 label: Description
+              expected:
+                $ref: '#/components/schemas/ExpectedSlotComponents'
               examples:
                 type: array
                 items:
@@ -4826,6 +4830,11 @@
           description: Array of slots
           items:
             $ref: '#/components/schemas/LayoutSlot'
+    ExpectedSlotComponents:
+      type: array
+      description: Active slot policy as explicit Canvas component IDs. An empty array leaves the slot unrestricted.
+      items:
+        type: string
     LayoutSlot:
       title: LayoutSlot
       type: object
@@ -4847,6 +4856,8 @@
         name:
           type: string
           description: The name of the slot
+        expected:
+          $ref: '#/components/schemas/ExpectedSlotComponents'
         components:
           type: array
           description: Array of components
```

### 2.3. `src/AutoSave/AutoSaveManager.php`

```diff
diff --git a/src/AutoSave/AutoSaveManager.php b/src/AutoSave/AutoSaveManager.php
--- a/src/AutoSave/AutoSaveManager.php
+++ b/src/AutoSave/AutoSaveManager.php
@@ -203,6 +203,7 @@
   }
 
   public function saveEntity(EntityInterface $entity, ?string $clientId = NULL): void {
+    \Drupal\canvas\SlotRestrictions::validateEntity($entity, draft: TRUE);
     $key = $this->getAutoSaveKey($entity);
     $data = self::normalizeEntity($entity);
     $data_hash = self::generateHash($data);
```

### 2.4. `src/ComponentSource/ComponentSourceBase.php`

```diff
diff --git a/src/ComponentSource/ComponentSourceBase.php b/src/ComponentSource/ComponentSourceBase.php
--- a/src/ComponentSource/ComponentSourceBase.php
+++ b/src/ComponentSource/ComponentSourceBase.php
@@ -115,12 +115,16 @@
       \array_keys(\array_filter($slot_definitions, \is_array(...))),
       static function (array $carry, string $slot_name) use ($slot_definitions): array {
         $slot_examples = $slot_definitions[$slot_name]['examples'] ?? [];
-        return $carry + [
-          $slot_name => [
-            'title' => $slot_definitions[$slot_name]['title'],
-            'example' => $slot_examples === [] ? '' : \current($slot_examples),
-          ],
+        $definition = [
+          'title' => $slot_definitions[$slot_name]['title'],
+          'example' => $slot_examples === [] ? '' : \current($slot_examples),
         ];
+        $expected = $slot_definitions[$slot_name]['expected'] ?? [];
+        if ($expected !== []) {
+          sort($expected);
+          $definition['expected'] = array_values(array_unique($expected));
+        }
+        return $carry + [$slot_name => $definition];
       },
       []
     );
```

### 2.5. `src/Controller/ApiLayoutController.php`

```diff
diff --git a/src/Controller/ApiLayoutController.php b/src/Controller/ApiLayoutController.php
--- a/src/Controller/ApiLayoutController.php
+++ b/src/Controller/ApiLayoutController.php
@@ -1121,7 +1121,7 @@
       if (!$slot_field->access('view') || !$slot_field->access('edit')) {
         continue;
       }
-      $layout[] = $this->buildRegion(
+      $region = $this->buildRegion(
         $field_name,
         $slot_field,
         $model,
@@ -1129,6 +1129,8 @@
         client_id: $field_name,
         name: $definition['label'],
       );
+      $region['expected'] = \Drupal\canvas\SlotRestrictions::forExposedSlot($template, $field_name);
+      $layout[] = $region;
     }
     return $layout;
   }
```

### 2.6. `src/Entity/Component.php`

```diff
diff --git a/src/Entity/Component.php b/src/Entity/Component.php
--- a/src/Entity/Component.php
+++ b/src/Entity/Component.php
@@ -733,7 +733,7 @@
    *
    * @param array $definition
    *
-   * @return array{title: string, description?: string, examples: string[]}
+   * @return array{title: string, description?: string, examples: string[], expected?: string[]}
    *   Clean definitions.
    */
   private static function cleanSlotDefinition(array $definition): array {
@@ -741,11 +741,12 @@
     // that aren't specified in the SDC metadata schema and in our config
     // schema.
     // @todo Remove when core enforces this - https://www.drupal.org/i/3522623
-    /** @var array{title: string, description?: string, examples: string[]} */
+    /** @var array{title: string, description?: string, examples: string[], expected?: string[]} */
     return \array_intersect_key($definition, array_flip([
       'title',
       'description',
       'examples',
+      'expected',
     ]));
   }
```

### 2.7. `src/Hook/SlotRestrictionHooks.php`

Utwórz nowy plik.

```php
<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\SlotRestrictions;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Enforces slot restrictions for saves outside the editor as well.
 */
final class SlotRestrictionHooks {

  /**
   * Implements hook_entity_presave().
   */
  #[Hook('entity_presave')]
  public function entityPresave(EntityInterface $entity): void {
    SlotRestrictions::validateEntity($entity);
  }

}
```

### 2.8. `src/Plugin/Field/FieldType/ComponentTreeItemList.php`

```diff
diff --git a/src/Plugin/Field/FieldType/ComponentTreeItemList.php b/src/Plugin/Field/FieldType/ComponentTreeItemList.php
--- a/src/Plugin/Field/FieldType/ComponentTreeItemList.php
+++ b/src/Plugin/Field/FieldType/ComponentTreeItemList.php
@@ -161,6 +161,7 @@
           'id' => $component_instance_uuid . '/' . $slot_name,
           'name' => $slot_name,
           'nodeType' => 'slot',
+          'expected' => \Drupal\canvas\SlotRestrictions::expected($item->getValue(), $slot_name),
         ];
         $child_build = self::buildLayoutAndModel($this->componentTreeItemsIterator(self::isChildOfComponentTreeItemSlot($component_instance_uuid, (string) $slot_name)), $host_entity);
         $built['model'] += $child_build['model'];
```

### 2.9. `src/Plugin/Validation/Constraint/ComponentTreeStructureConstraintValidator.php`

```diff
diff --git a/src/Plugin/Validation/Constraint/ComponentTreeStructureConstraintValidator.php b/src/Plugin/Validation/Constraint/ComponentTreeStructureConstraintValidator.php
--- a/src/Plugin/Validation/Constraint/ComponentTreeStructureConstraintValidator.php
+++ b/src/Plugin/Validation/Constraint/ComponentTreeStructureConstraintValidator.php
@@ -56,6 +56,10 @@
   public function validate(mixed $value, Constraint $constraint): void {
     if ($value === NULL) {
       return;
+    }
+    $root_expected = [];
+    if ($value instanceof ComponentTreeItemList && ($host = $value->getParent()?->getValue()) instanceof FieldableEntityInterface) {
+      $root_expected = \Drupal\canvas\SlotRestrictions::forField($host, $value->getName());
     }
     if ($value instanceof ComponentTreeItemList) {
       $value = $value->getValue();
@@ -184,6 +188,13 @@
         $violation->getCause(),
       ));
     }
+    foreach (\Drupal\canvas\SlotRestrictions::violations($value, $root_expected) as $delta => $message) {
+      $path = self::translatePropertyPath($base_property_path, "[$delta][component_id]", $this->context->getPropertyPath());
+      $this->context->getViolations()->add(new ConstraintViolation(
+        $message, $message, [], $this->context->getRoot(), $path, $value[$delta]['component_id'],
+      ));
+    }
+
   }
 
   private static function translatePropertyPath(string $base_path, string $property_path, string $context_path = ''): string {
```

### 2.10. `src/SlotRestrictions.php`

Utwórz nowy plik.

```php
<?php

declare(strict_types=1);

namespace Drupal\canvas;

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentTreeEntityInterface;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\Plugin\Canvas\ComponentSource\Marker;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Enforces explicit component IDs declared in slot metadata.
 *
 * @internal
 */
final class SlotRestrictions {

  /**
   * Gets the active slot policy, including for older component instances.
   */
  public static function expected(array $row, string $slot): array {
    $component = Component::load($row['component_id'] ?? '');
    if (!$component) {
      return [];
    }
    return $component->getSlotDefinitions($component->getActiveVersion())[$slot]['expected'] ?? [];
  }

  /**
   * Resolves the template-owned parent of an exposed slot field.
   */
  public static function forField(FieldableEntityInterface $entity, string $field, bool $draft = FALSE): array {
    $template = ContentTemplate::loadForEntity($entity, 'full');
    if (!$template || !$template->status()) {
      return [];
    }
    if ($draft) {
      $pending = \Drupal::service(AutoSaveManager::class)->getAutoSaveEntity($template)->entity;
      if ($pending instanceof ContentTemplate) {
        $template = $pending;
      }
    }
    return self::forExposedSlot($template, $field);
  }

  /**
   * Gets the restriction of one exposed slot on a particular template.
   */
  public static function forExposedSlot(ContentTemplate $template, string $field): array {
    $definition = $template->getExposedSlots()[$field] ?? NULL;
    if (!$definition) {
      return [];
    }
    $parent = $template->getComponentTree()->getComponentTreeItemByUuid($definition['component_uuid']);
    return $parent ? self::expected($parent->getValue(), $definition['slot_name']) : [];
  }

  /**
   * Returns violations keyed by row delta, including exposed-field roots.
   */
  public static function violations(array $rows, array $root_expected = []): array {
    $by_uuid = [];
    foreach ($rows as $row) {
      if (isset($row['uuid'])) {
        $by_uuid[$row['uuid']] = $row;
      }
    }
    $violations = [];
    foreach ($rows as $delta => $row) {
      $parent_uuid = $row['parent_uuid'] ?? NULL;
      if ($parent_uuid) {
        $parent = $by_uuid[$parent_uuid] ?? NULL;
        // Structural validation reports missing parents and slot names.
        $expected = $parent ? self::expected($parent, $row['slot'] ?? '') : [];
      }
      else {
        $expected = $root_expected;
        // A sole marker represents an intentionally empty override.
        if (count($rows) === 1 && ($row['component_id'] ?? '') === Marker::EMPTY_SLOT_COMPONENT_ID) {
          continue;
        }
      }
      if ($expected && !in_array($row['component_id'] ?? '', $expected, TRUE)) {
        $violations[$delta] = sprintf('This slot accepts only: %s. The component %s cannot be added here.', implode(', ', $expected), $row['component_id'] ?? '');
      }
    }
    return $violations;
  }

  /**
   * Checks only slot restrictions, preserving other incomplete draft inputs.
   */
  public static function validateEntity(EntityInterface $entity, bool $draft = FALSE): void {
    $trees = [];
    if ($entity instanceof ComponentTreeEntityInterface) {
      $trees['component_tree'] = [$entity->getComponentTree()->getValue() ?? [], []];
    }
    elseif ($entity instanceof FieldableEntityInterface) {
      foreach ($entity->getFieldDefinitions() as $field => $definition) {
        if ($definition->getType() === 'component_tree') {
          $trees[$field] = [$entity->get($field)->getValue() ?? [], self::forField($entity, $field, $draft)];
        }
      }
    }
    $violations = new ConstraintViolationList();
    foreach ($trees as $field => [$rows, $expected]) {
      foreach (self::violations($rows, $expected) as $delta => $message) {
        $violations->add(new ConstraintViolation($message, $message, [], $entity, "$field.$delta.component_id", $rows[$delta]['component_id']));
      }
    }
    if (count($violations)) {
      throw new ConstraintViolationException($violations);
    }
  }

}
```

### 2.11. `ui/src/app/store.ts`

```diff
diff --git a/ui/src/app/store.ts b/ui/src/app/store.ts
--- a/ui/src/app/store.ts
+++ b/ui/src/app/store.ts
@@ -1,4 +1,5 @@
 import undoable from 'redux-undo';
+import { toast } from 'sonner';
 import { v4 as uuidv4 } from 'uuid';
 import { combineSlices, configureStore } from '@reduxjs/toolkit';
 import { setupListeners } from '@reduxjs/toolkit/query';
@@ -17,6 +18,7 @@
   setTranslations,
   setUpdatePreview,
 } from '@/features/layout/layoutModelSlice';
+import { slotRestrictionViolations } from '@/features/layout/slotRestrictions';
 import { notificationsSlice } from '@/features/notifications/notificationsSlice';
 import {
   pageDataOwnerReducer,
@@ -35,6 +37,7 @@
   pushUndo,
   setLatestUndoRedoActionId,
   uiSlice,
+  unsetUpdatingComponent,
 } from '@/features/ui/uiSlice';
 import { assetLibraryApi } from '@/services/assetLibrary';
 import { brandKitApi } from '@/services/brandKit';
@@ -246,6 +249,54 @@
     return next(action);
   };
 
+// Predict structural edits with the same root reducer used by the store.
+// Reject the whole action before history, model, or preview state is changed.
+const slotRestrictionGuard: Middleware<{}, RootState> =
+  (api) => (next) => (action) => {
+    const type = (action as UnknownAction).type;
+    const structuralActions = [
+      'layoutModel/insertNodes',
+      'layoutModel/moveNode',
+      'layoutModel/duplicateNode',
+      'layoutModel/sortNode',
+      'layoutModel/shiftNode',
+      'layoutModel/overrideSlotDefaultContent',
+      'layoutModel/revertSlotOverride',
+      '@@redux-undo/layoutModel_UNDO',
+      '@@redux-undo/layoutModel_REDO',
+    ];
+    if (structuralActions.includes(type)) {
+      const state = api.getState();
+      const candidate = rootReducer(state, action as UnknownAction);
+      const components =
+        componentAndLayoutApi.endpoints.getComponents.select()(state).data ??
+        {};
+      const before = slotRestrictionViolations(
+        state.layoutModel.present.layout,
+        components,
+      );
+      const after = slotRestrictionViolations(
+        candidate.layoutModel.present.layout,
+        components,
+      );
+      for (const [edge, message] of after) {
+        if (!before.has(edge)) {
+          api.dispatch(unsetUpdatingComponent());
+          toast.error(message, {
+            style: {
+              width: 'auto',
+              height: 'auto',
+              whiteSpace: 'normal',
+              padding: '12px',
+            },
+          });
+          return false;
+        }
+      }
+    }
+    return next(action);
+  };
+
 // The store setup is wrapped in `makeStore` to allow reuse
 // when setting up tests that need the same store config
 export const makeStore = (preloadedState?: Partial<RootState>) => {
@@ -255,6 +306,7 @@
     // and other useful features of `rtk-query`.
     middleware: (getDefaultMiddleware) => {
       return getDefaultMiddleware().concat(
+        slotRestrictionGuard,
         patternApi.middleware,
         pageVariantsApi.middleware,
         assetLibraryApi.middleware,
```

### 2.12. `ui/src/components/list/ComponentList.tsx`

```diff
diff --git a/ui/src/components/list/ComponentList.tsx b/ui/src/components/list/ComponentList.tsx
--- a/ui/src/components/list/ComponentList.tsx
+++ b/ui/src/components/list/ComponentList.tsx
@@ -2,8 +2,15 @@
 import { useErrorBoundary } from 'react-error-boundary';
 import { skipToken } from '@reduxjs/toolkit/query';
 
+import { useAppSelector } from '@/app/hooks';
 import ListItem from '@/components/list/ListItem';
+import {
+  selectExposedSlots,
+  selectLayout,
+} from '@/features/layout/layoutModelSlice';
+import { targetSlotExpected } from '@/features/layout/slotRestrictions';
 import { LayoutItemType } from '@/features/ui/primaryPanelSlice';
+import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
 import { useCanvasHeadlessSettings } from '@/hooks/useCanvasHeadlessSettings';
 import {
   useGetComponentsQuery,
@@ -35,6 +42,9 @@
 
 const ComponentList = ({ searchTerm, visibility }: ComponentListProps) => {
   const headlessSettings = useCanvasHeadlessSettings();
+  const layout = useAppSelector(selectLayout);
+  const exposedSlots = useAppSelector(selectExposedSlots);
+  const selected = useAppSelector(selectSelectedComponentUuid);
   const { data: allComponents, error, isLoading } = useGetComponentsQuery();
   const {
     data: configuredFrontends,
@@ -102,6 +112,25 @@
     );
   }, [activeFrontendComponentIds, components, visibility]);
 
+  // Hover targets change throughout a drag. Filtering on them can unmount
+  // the active draggable and repeatedly reset dnd-kit's collision target.
+  // Selection changes only after insertion or an explicit selection action.
+  const expected = useMemo(
+    () => targetSlotExpected(layout, selected, allComponents, exposedSlots),
+    [layout, selected, allComponents, exposedSlots],
+  );
+  const slotComponents = useMemo(
+    () =>
+      expected.length
+        ? Object.fromEntries(
+            Object.entries(visibleComponents ?? {}).filter(([id]) =>
+              expected.includes(id),
+            ),
+          )
+        : visibleComponents,
+    [expected, visibleComponents],
+  );
+
   useEffect(() => {
     if (error || foldersError || frontendsError) {
       showBoundary(error || foldersError || frontendsError);
@@ -114,7 +143,7 @@
 
   return (
     <LibraryItemList<CanvasComponent>
-      items={visibleComponents as ComponentsList}
+      items={slotComponents as ComponentsList}
       folders={folders as FolderData}
       isLoading={isLoading || foldersLoading || frontendsLoading}
       searchTerm={searchTerm}
```

### 2.13. `ui/src/features/layout/layoutModelSlice.ts`

```diff
diff --git a/ui/src/features/layout/layoutModelSlice.ts b/ui/src/features/layout/layoutModelSlice.ts
--- a/ui/src/features/layout/layoutModelSlice.ts
+++ b/ui/src/features/layout/layoutModelSlice.ts
@@ -45,6 +45,7 @@
 }
 
 export interface RegionNode {
+  expected?: string[];
   name: string;
   id: string;
   nodeType: NodeType.Region;
@@ -59,6 +60,7 @@
 }
 
 export interface SlotNode {
+  expected?: string[];
   nodeType: NodeType.Slot;
   id: string;
   name: string;
@@ -968,6 +970,8 @@
       Object.keys(component.metadata.slots).forEach((name) => {
         slots.push({
           id: `${uuid}/${name}`,
+          expected: (component.metadata.slots[name] as { expected?: string[] })
+            .expected,
           name: name,
           nodeType: NodeType.Slot,
           components: [],
@@ -991,13 +995,15 @@
       },
     };
 
-    dispatch(
+    const inserted = dispatch(
       insertNodes({
         to,
         layoutModel,
         useUUID: uuid,
       }),
     );
+
+    if (!inserted) return;
 
     // Get the new state immediately after the insertNode action was called so that setSelectedComponent will find
     // the newly added component.
@@ -1217,13 +1223,15 @@
       return;
     }
 
-    dispatch(
+    const inserted = dispatch(
       insertNodes({
         to,
         layoutModel,
         useUUID: uuid,
       }),
     );
+
+    if (!inserted) return;
 
     // Get the new state immediately after the insertNodes action was called so that setSelectedComponent will find
     // the newly added component.
```

### 2.14. `ui/src/features/layout/slotRestrictions.ts`

Utwórz nowy plik.

```typescript
import type { ComponentsList } from '@/types/Component';
import type { ComponentNode, RegionNode, SlotNode } from './layoutModelSlice';

type SlotContainer = RegionNode | SlotNode;

function expectedFor(
  container: SlotContainer,
  parent: ComponentNode | undefined,
  components: ComponentsList,
): string[] {
  const definition = parent
    ? components[parent.type.split('@')[0]]?.metadata?.slots?.[
        (container as SlotNode).name
      ]
    : undefined;
  return definition ? (definition.expected ?? []) : (container.expected ?? []);
}

/** Collect invalid edges so existing invalid content can still be repaired. */
export function slotRestrictionViolations(
  layout: RegionNode[],
  components: ComponentsList = {},
): Map<string, string> {
  const violations = new Map<string, string>();
  function visit(container: SlotContainer, parent?: ComponentNode) {
    const expected = expectedFor(container, parent, components);
    for (const child of container.components) {
      const type = child.type.split('@')[0];
      const emptyOverride =
        container.nodeType === 'region' &&
        container.components.length === 1 &&
        type === 'marker.empty_slot';
      if (expected.length && !expected.includes(type) && !emptyOverride) {
        violations.set(
          `${container.id}/${child.uuid}/${type}`,
          `This slot accepts only: ${expected.join(', ')}.`,
        );
      }
      for (const slot of child.slots) {
        visit(slot, child);
      }
    }
  }
  layout.forEach((region) => visit(region));
  return violations;
}

/** Resolve a selected slot, exposed region, or a selected child's container. */
export function targetSlotExpected(
  layout: RegionNode[],
  target: string | undefined,
  components: ComponentsList = {},
  exposedSlots: Record<
    string,
    { componentUuid: string; slotName: string }
  > = {},
): string[] {
  if (!target) return [];
  const alias = Object.entries(exposedSlots).find(
    ([field, slot]) =>
      `${slot.componentUuid}/${slot.slotName}` === target &&
      layout.some((region) => region.id === field),
  )?.[0];
  target = alias ?? target;
  let result: string[] = [];
  function visit(container: SlotContainer, parent?: ComponentNode) {
    if (
      container.id === target ||
      container.components.some((child) => child.uuid === target)
    ) {
      result = expectedFor(container, parent, components);
    }
    for (const child of container.components) {
      child.slots.forEach((slot) => visit(slot, child));
    }
  }
  layout.forEach((region) => visit(region));
  return result;
}
```

### 2.15. `ui/src/hooks/useCopyPasteComponents.ts`

```diff
diff --git a/ui/src/hooks/useCopyPasteComponents.ts b/ui/src/hooks/useCopyPasteComponents.ts
--- a/ui/src/hooks/useCopyPasteComponents.ts
+++ b/ui/src/hooks/useCopyPasteComponents.ts
@@ -78,14 +78,14 @@
     to[to.length - 1]++;
 
     const assignedUUID = uuidv4();
-    dispatch(
+    const inserted = dispatch(
       insertNodes({
         to: to,
         layoutModel: componentFromClipboard,
         useUUID: assignedUUID,
       }),
     );
-    setSelectedComponent(assignedUUID);
+    if (inserted) setSelectedComponent(assignedUUID);
   };
 
   return { pasteAfterSelectedComponent, copySelectedComponent };
```

### 2.16. `ui/src/hooks/useDropFromLayoutHandler.ts`

```diff
diff --git a/ui/src/hooks/useDropFromLayoutHandler.ts b/ui/src/hooks/useDropFromLayoutHandler.ts
--- a/ui/src/hooks/useDropFromLayoutHandler.ts
+++ b/ui/src/hooks/useDropFromLayoutHandler.ts
@@ -55,8 +55,8 @@
     // if we got this far, then we have a valid location to move the dragged component to!
     // @todo We should optimistically move the elementsInsideIframe to the new location in the iFrames dom.
     // for now, we pass true here which will put the elementsInsideIframe into a 'pending move' state.
-    afterDrag(elementsInsideIframe, true, activeUuid);
-    dispatch(moveNode({ uuid: activeUuid, to: dropPath }));
+    const moved = dispatch(moveNode({ uuid: activeUuid, to: dropPath }));
+    afterDrag(elementsInsideIframe, Boolean(moved), activeUuid);
   }
 
   return { handleExistingDrop };
```

## 3. Przebuduj edytor

W katalogu web/modules/contrib/canvas:

```bash
CYPRESS_INSTALL_BINARY=0 npm ci --no-audit --no-fund
npm run build --workspace=@drupal-canvas/ui -- --force
```

Następnie w katalogu projektu:

```bash
drush cache:rebuild
```

Wymagany Node to >=22.19.0 <23 albo >=24.5.0. Opcja --force wymusza przebudowę
źródeł modułu znajdujących się zwykle poza kontrolą wersji projektu.

Composer może zastąpić ręczne zmiany przy ponownej instalacji Canvas.
Po ich przepisaniu zapisz diff w docelowym projekcie i podłącz go do swojego
procesu instalacji zależności. Taki lokalny plik powstaje na miejscu i pozwala
odtwarzać ręcznie wpisane zmiany.

## 4. Przygotuj minimalne komponenty Next

W katalogu komponentów używanym przez konfigurację Canvas utwórz trzy katalogi.

### simple_group/component.yml

```yaml
name: Simple group
machineName: simple_group
status: true
required: []
props:
  properties: {}
slots:
  items:
    title: Items
    expected:
      - js.simple_item
```

### simple_group/index.jsx

```jsx
export default function SimpleGroup({ items }) {
  return <div style={{ display: 'grid', gap: '1rem' }}>{items}</div>;
}
```

### simple_item/component.yml

```yaml
name: Simple item
machineName: simple_item
status: true
required:
  - text
props:
  properties:
    text:
      title: Text
      type: string
      examples:
        - Example item
slots: {}
```

### simple_item/index.jsx

```jsx
export default function SimpleItem({ text = 'Example item' }) {
  return <p>{text}</p>;
}
```

### simple_container/component.yml

```yaml
name: Simple container
machineName: simple_container
status: true
required: []
props:
  properties: {}
slots:
  content:
    title: Content
```

### simple_container/index.jsx

```jsx
export default function SimpleContainer({ content }) {
  return <div>{content}</div>;
}
```

Zsynchronizuj metadane komponentów przez skonfigurowany w projekcie frontend
Canvas. W tym kroku wykorzystaj istniejące połączenie Next–Canvas, jego adresy
oraz konfigurację OAuth. Jeżeli połączenie jest dopiero przygotowywane, trzeba
najpierw skonfigurować canvas_headless i endpointy SDK dla tego projektu.

## 5. Skonfiguruj i sprawdź oba przypadki w Canvas

1. Utwórz typ treści i Content Template dla jego widoku Full.
2. Dodaj Simple group i Simple container jako dwa osobne elementy szablonu.
3. Wystaw slot Items grupy oraz Content kontenera przez funkcję Expose slot.
   Użyj dwóch pól component_tree utworzonych przez dialog, np.
   canvas_slot_items i canvas_slot_content. To dwa niezagnieżdżone Exposed Slots.
4. Utwórz dwa nody tego typu. Każdy automatycznie korzysta z szablonu.
5. Otwórz jeden node w Canvas. Dodaj Simple item do wystawionego regionu.
6. Spróbuj dodać Simple group do tego samego regionu. Powinien pojawić się
   komunikat odrzucenia, bez zmiany drzewa i migania.
7. W edytorze noda wstaw Simple group do regionu Content. Grupa należy teraz
   do treści tego noda. W jej zwykłym slocie Items sprawdź dodawanie Simple item
   i odrzucanie Simple group.
8. Sprawdź kopiowanie, wklejanie, przenoszenie i publikację poprawnych elementów.
9. Sprawdź pusty override i powrót do treści domyślnej Exposed Slot.
10. Zmień wspólny szablon i potwierdź zmianę na obu stronach Next.

Reguły używają pełnych identyfikatorów Canvas, np. js.simple_item.
Brak expected lub pusta lista pozostawia slot swobodny. Reguła obowiązuje
bezpośrednie dzieci wskazanego slotu. Treść slotu jest renderowana zwykłym SDK
Canvas, więc osobna implementacja ograniczeń w Next nie jest potrzebna.
