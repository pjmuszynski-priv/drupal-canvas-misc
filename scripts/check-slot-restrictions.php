<?php

/**
 * Checks slot restrictions through validation and the authenticated Layout API.
 *
 * Run: ddev drush php:script scripts/check-slot-restrictions.php
 * All fixtures use a temporary bundle and are deleted in finally.
 */

use Drupal\canvas\AutoSave\AutoSaveManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\canvas\Exception\ConstraintViolationException;
use Drupal\canvas\SlotRestrictions;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  echo "PASS: $message\n";
};
$bundle = 'slot_check_' . bin2hex(random_bytes(3));
$type = $field = $template = $node = $role = $user = $form = $view = NULL;
$row = static function (string $id, array $inputs = [], ?string $parent = NULL, ?string $slot = NULL): array {
  return [
    'uuid' => \Drupal::service('uuid')->generate(),
    'component_id' => $id,
    'component_version' => Component::load($id)->getActiveVersion(),
    'inputs' => $inputs,
    'parent_uuid' => $parent,
    'slot' => $slot,
  ];
};
try {
  $check(SlotRestrictions::expected(['component_id' => 'js.tabs'], 'items') === ['js.tab-item'], 'Tabs metadata declares Tab Item only');
  $type = NodeType::create(['type' => $bundle, 'name' => 'Temporary slot restriction check']);
  $type->save();
  $field = FieldConfig::create([
    'field_name' => 'canvas_slot_main', 'entity_type' => 'node', 'bundle' => $bundle, 'label' => 'Content',
  ]);
  $field->save();
  $form = EntityFormDisplay::create(['targetEntityType' => 'node', 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE]);
  $form->setComponent('title', ['type' => 'string_textfield'])->save();
  $view = EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => $bundle, 'mode' => 'full', 'status' => TRUE]);
  $view->save();

  $container = $row('js.container', ['columns' => 1]);
  $template = ContentTemplate::create([
    'id' => "node.$bundle.full",
    'content_entity_type_id' => 'node',
    'content_entity_type_bundle' => $bundle,
    'content_entity_type_view_mode' => 'full',
    'component_tree' => [$container],
    'exposed_slots' => [
      'canvas_slot_main' => ['component_uuid' => $container['uuid'], 'slot_name' => 'content', 'label' => 'Content'],
    ],
    'status' => TRUE,
  ]);
  $template->save();
  $tabs = $row('js.tabs', ['header' => 'Tabs test']);
  $item = $row('js.tab-item', ['tabLabel' => 'First'], $tabs['uuid'], 'items');
  $text = $row('js.text', ['text' => ['value' => '<p>Panel body</p>', 'format' => 'canvas_html_block']], $item['uuid'], 'body');
  $node = Node::create([
    'type' => $bundle, 'title' => 'Slot restriction check', 'uid' => 1, 'status' => TRUE,
    'canvas_slot_main' => [$tabs, $item, $text],
  ]);
  $check(count($node->validate()) === 0, 'Tab Item with arbitrary body passes entity validation');
  $node->save();

  $invalid = clone $node;
  $bad_text = $text;
  $bad_text['parent_uuid'] = $tabs['uuid'];
  $bad_text['slot'] = 'items';
  $invalid->set('canvas_slot_main', [$tabs, $item, $bad_text]);
  $old_versions = array_values(array_diff(Component::load('js.tabs')->getVersions(), [$tabs['component_version']]));
  if ($old_versions) {
    $old_tabs = $tabs;
    $old_tabs['component_version'] = $old_versions[0];
    $check(count(SlotRestrictions::violations([$old_tabs, $item, $bad_text])) === 1, 'Older component versions cannot bypass the active policy');
  }
  $check(count($invalid->validate()) > 0, 'Entity validation rejects text directly in Tabs.items');
  try {
    SlotRestrictions::validateEntity($invalid);
    throw new RuntimeException('Invalid nested tree was accepted');
  }
  catch (ConstraintViolationException) {
    $check(TRUE, 'Shared validator rejects invalid nested children');
  }
  try {
    $invalid->save();
    throw new RuntimeException('Direct entity save bypassed restrictions');
  }
  catch (\Drupal\Core\Entity\EntityStorageException $e) {
    $check(str_contains($e->getMessage(), 'This slot accepts only'), 'Direct entity save cannot bypass restrictions');
  }

  $password = bin2hex(random_bytes(20));
  $role = Role::create(['id' => $bundle, 'label' => 'Temporary slot checker']);
  foreach (['access content', 'view own unpublished content', "edit any $bundle content", 'publish auto-saves', 'access canvas headless preview', 'use text format canvas_html_block'] as $permission) {
    $role->grantPermission($permission);
  }
  $role->save();
  $user = User::create(['name' => $bundle, 'status' => 1, 'pass' => $password, 'roles' => [$role->id()]]);
  $user->save();
  $client = new Client(['base_uri' => 'https://d11-next.ddev.site', 'cookies' => TRUE, 'http_errors' => FALSE, 'timeout' => 30]);
  $login = $client->post('/user/login?_format=json', ['json' => ['name' => $user->getAccountName(), 'pass' => $password]]);
  $check($login->getStatusCode() === 200, 'Temporary editor logs in');
  $login_data = json_decode((string) $login->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
  $headers = ['Accept' => 'application/json', 'X-CSRF-Token' => $login_data['csrf_token']];
  $url = '/canvas/api/v0/layout/node/' . $node->id();
  $get = static function () use ($client, $headers, $url): array {
    $response = $client->get($url, ['headers' => $headers]);
    if ($response->getStatusCode() !== 200) {
      throw new RuntimeException('Layout GET: ' . $response->getStatusCode() . ' ' . substr((string) $response->getBody(), 0, 800));
    }
    return json_decode((string) $response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
  };
  $payload = static fn(array $layout): array => array_intersect_key($layout, array_flip(['layout', 'model', 'autoSaves', 'entity_form_fields'])) + ['clientInstanceId' => 'slot-restriction-check'];
  $assertRejected = static function (array $bad, array $before) use ($client, $headers, $url, $get, $check): void {
    $response = $client->post($url, ['headers' => $headers, 'json' => $bad]);
    $body = (string) $response->getBody();
    $check($response->getStatusCode() === 422 && str_contains($body, 'This slot accepts only'), 'Layout API rejects invalid children with a slot-specific 422');
    $after = $get();
    $check($after['layout'] === $before['layout'] && $after['autoSaves'] === $before['autoSaves'], 'Rejected request preserves the tree and auto-save hashes');
  };
  $layout = $get();
  $check($layout['layout'][0]['components'][0]['slots'][0]['expected'] === ['js.tab-item'], 'Regular slot restriction reaches the editor');
  $bad = $payload($layout);
  $tabs_node = &$bad['layout'][0]['components'][0];
  $tabs_node['slots'][0]['expected'] = ['js.text'];
  $tabs_node['slots'][0]['components'][0]['slots'][0]['components'] = [];
  $tabs_node['slots'][0]['components'][] = $layout['layout'][0]['components'][0]['slots'][0]['components'][0]['slots'][0]['components'][0];
  unset($tabs_node);
  $assertRejected($bad, $layout);

  // Reuse the temporary field as the exposed items slot of template-owned Tabs.
  $item['parent_uuid'] = NULL;
  $item['slot'] = NULL;
  $node->set('canvas_slot_main', [$item, $text])->save();
  $template->setComponentTree([$tabs]);
  $template->set('exposed_slots', [
    'canvas_slot_main' => ['component_uuid' => $tabs['uuid'], 'slot_name' => 'items', 'label' => 'Tabs'],
  ])->save();
  $layout = $get();
  $check($layout['layout'][0]['expected'] === ['js.tab-item'], 'Exposed slot carries the template-owned restriction');
  $bad = $payload($layout);
  $bad['layout'][0]['expected'] = ['js.text'];
  $bad['layout'][0]['components'][0]['slots'][0]['components'] = [];
  $bad['layout'][0]['components'][] = $layout['layout'][0]['components'][0]['slots'][0]['components'][0];
  $assertRejected($bad, $layout);

  $invalid = clone $node;
  $text['parent_uuid'] = NULL;
  $text['slot'] = NULL;
  $invalid->set('canvas_slot_main', [$text]);
  $check(count($invalid->validate()) > 0, 'Publication validation rejects an invalid exposed-field root');
  try {
    \Drupal::service(AutoSaveManager::class)->saveEntity($invalid);
    throw new RuntimeException('Invalid root reached auto-save');
  }
  catch (ConstraintViolationException) {
    $check(TRUE, 'Direct auto-save rejects invalid exposed roots');
  }
  $empty = clone $node;
  $empty->set('canvas_slot_main', [$row('marker.empty_slot')]);
  SlotRestrictions::validateEntity($empty);
  $check(TRUE, 'Empty exposed-slot override remains valid');
  $empty->set('canvas_slot_main', []);
  SlotRestrictions::validateEntity($empty);
  $check(TRUE, 'Reverting to the template default remains valid');

  $valid = $payload($layout);
  $valid['model'][$item['uuid']]['resolved']['tabLabel'] = 'Updated label';
  $response = $client->post($url, ['headers' => $headers, 'json' => $valid]);
  $check($response->getStatusCode() === 200, 'Valid exposed-slot edit auto-saves');
  $pending_response = $client->get('/canvas/api/v0/auto-saves/pending', ['headers' => $headers]);
  $pending = json_decode((string) $pending_response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
  $selected = array_filter($pending['data'], static fn(array $entry): bool => $entry['entity_type'] === 'node' && (string) $entry['entity_id'] === (string) $node->id());
  $response = $client->post('/canvas/api/v0/auto-saves/publish', ['headers' => $headers, 'json' => $selected]);
  $check($response->getStatusCode() === 200, 'Valid exposed-slot edit publishes');
  $response = $client->get('https://d11-next.ddev.site:3000/node/' . $node->id());
  $html = (string) $response->getBody();
  $check($response->getStatusCode() === 200 && str_contains($html, 'Updated label') && str_contains($html, 'Panel body'), 'Next renders the published restricted exposed slot');
}
finally {
  $node?->delete();
  $user?->delete();
  $template?->delete();
  $form?->delete();
  $view?->delete();
  $field?->delete();
  $type?->delete();
  $role?->delete();
}
