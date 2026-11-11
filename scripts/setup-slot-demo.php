<?php

/**
 * Creates the two-slot demo without replacing existing demo content.
 *
 * Run: ddev drush php:script scripts/setup-slot-demo.php
 */

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\user\Entity\Role;

$headless = \Drupal::configFactory()->getEditable('canvas_headless.settings');
$frontends = $headless->get('frontends') ?? [];
foreach ($frontends as &$frontend) {
  if ($frontend['url'] === 'https://d11-next.ddev.site:3000' && !in_array('js.template_shell', $frontend['components'], TRUE)) {
    $frontend['components'][] = 'js.template_shell';
  }
}
unset($frontend);
$headless->set('frontends', $frontends)->save();

$bundle = 'slot_demo';
$host_uuid = '6750b4b3-b6f3-43ee-a9f0-c39890a34401';
$uuid_service = \Drupal::service('uuid');
foreach (['js.template_shell', 'js.heading', 'js.text', 'js.button'] as $id) {
  if (!Component::load($id)) {
    throw new RuntimeException("Push frontend components first: missing $id");
  }
}
if (!NodeType::load($bundle)) {
  NodeType::create([
    'type' => $bundle,
    'name' => 'Two-slot editorial page',
    'description' => 'A shared Canvas content template with two independent exposed slots.',
    'new_revision' => TRUE,
  ])->save();
}
$slots = ['canvas_slot_main' => 'Main content', 'canvas_slot_additional' => 'Additional content'];
foreach ($slots as $name => $label) {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => 'component_tree',
      'cardinality' => -1,
    ])->save();
  }
  if (!FieldConfig::loadByName('node', $bundle, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $label,
    ])->save();
  }
}
if (!EntityFormDisplay::load("node.$bundle.default")) {
  EntityFormDisplay::create(['targetEntityType' => 'node', 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE])
    ->setComponent('title', ['type' => 'string_textfield', 'weight' => 0])
    ->setComponent('path', ['type' => 'path', 'weight' => 1])
    ->save();
}
if (!EntityViewDisplay::load("node.$bundle.full")) {
  EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => $bundle, 'mode' => 'full', 'status' => TRUE])->save();
}
$tree_row = static function (string $id, array $inputs) use ($uuid_service): array {
  return [
    'uuid' => $uuid_service->generate(),
    'component_id' => $id,
    'component_version' => Component::load($id)->getActiveVersion(),
    'inputs' => $inputs,
  ];
};
$text = static fn(string $value): array => ['text' => ['value' => $value, 'format' => 'canvas_html_block']];
$heading = static fn(string $value): array => [
  'heading' => $value,
  'textColor' => 'dark',
  'headingElement' => 'h2',
  'headingSize' => 'medium',
  'layout' => 'left_aligned',
];
$template = ContentTemplate::load("node.$bundle.full");
if (!$template) {
  $template = ContentTemplate::create([
    'id' => "node.$bundle.full",
    'content_entity_type_id' => 'node',
    'content_entity_type_bundle' => $bundle,
    'content_entity_type_view_mode' => 'full',
    'component_tree' => [[
      'uuid' => $host_uuid,
      'component_id' => 'js.template_shell',
      'component_version' => Component::load('js.template_shell')->getActiveVersion(),
      'inputs' => [
        'title' => [
          'sourceType' => 'entity-field',
          'expression' => 'ℹ︎␜entity:node:slot_demo␝title␞␟value',
        ],
      ],
    ]],
    'exposed_slots' => [
      'canvas_slot_main' => ['component_uuid' => $host_uuid, 'slot_name' => 'main', 'label' => 'Main content'],
      'canvas_slot_additional' => ['component_uuid' => $host_uuid, 'slot_name' => 'supplementary', 'label' => 'Additional content'],
    ],
  ]);
  $template->setStatus(TRUE)->save();
}
$role_permissions = [
  'access content', 'access administration pages', 'access content overview',
  'view the administration theme', 'create slot_demo content',
  'edit any slot_demo content', 'view own unpublished content',
  'publish auto-saves', 'access canvas headless preview', 'use text format canvas_html_block', 'create url aliases',
];
foreach (['slot_demo_editor' => 'Slot demo: moderator', 'slot_demo_designer' => 'Slot demo: template designer'] as $id => $label) {
  $role = Role::load($id) ?? Role::create(['id' => $id, 'label' => $label]);
  foreach ($role_permissions as $permission) {
    $role->grantPermission($permission);
  }
  if ($id === 'slot_demo_designer') {
    $role->grantPermission('administer content templates');
  }
  $role->save();
}
$pages = [
  ['uuid' => 'd38da97b-d941-4312-82b8-885e438b3801', 'slug' => 'alpha', 'title' => 'Good ideas start with a little space.',
    'heading' => 'Room to explore', 'body' => '<p>Every project begins with a question. This is where we collect the observations that help us find a better answer.</p><p>Slow down, look closer, and make room for the unexpected.</p>',
    'aside' => 'From the notebook', 'note' => '<p>A small collection of ideas worth returning to. New notes, considered perspectives, and things we are learning along the way.</p>'],
  ['uuid' => 'd38da97b-d941-4312-82b8-885e438b3802', 'slug' => 'beta', 'title' => 'Small details. Lasting impressions.',
    'heading' => 'The work behind the work', 'body' => '<p>The best experiences feel effortless. Behind them are hundreds of small decisions, each made with care.</p><p>Here we share a few of those decisions and the people who make them.</p>',
    'aside' => 'Stay curious', 'note' => '<p>Follow the process, meet the team, and discover what we are working on next.</p>'],
];
foreach ($pages as $page) {
  $existing = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $page['uuid']]);
  $node = reset($existing);
  if (!$node) {
    $node = Node::create([
      'type' => $bundle, 'uuid' => $page['uuid'], 'title' => $page['title'], 'uid' => 1, 'status' => TRUE,
      'canvas_slot_main' => [$tree_row('js.heading', $heading($page['heading'])), $tree_row('js.text', $text($page['body']))],
      'canvas_slot_additional' => [
        $tree_row('js.heading', $heading($page['aside'])),
        $tree_row('js.text', $text($page['note'])),
        $tree_row('js.button', ['text' => 'Explore ' . ($page['slug'] === 'alpha' ? 'Beta' : 'Alpha'), 'variant' => 'outline_dark', 'link' => '/slot-demo/' . ($page['slug'] === 'alpha' ? 'beta' : 'alpha')]),
      ],
    ]);
    $violations = $node->validate();
    if (count($violations)) {
      throw new RuntimeException((string) $violations);
    }
    $node->save();
  }
  $alias = '/slot-demo/' . $page['slug'];
  if (!\Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['alias' => $alias])) {
    PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => $alias, 'langcode' => 'en'])->save();
  }
  echo "$alias → /node/{$node->id()} → /canvas/editor/node/{$node->id()}\n";
}
echo "Two-slot demo is ready. Existing demo content was preserved.\n";
