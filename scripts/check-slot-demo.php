<?php

/**
 * Exercises the real HTTP API as a temporary moderator, then removes test data.
 *
 * Run: ddev drush php:script scripts/check-slot-demo.php
 */

use Drupal\canvas\Entity\ContentTemplate;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;

$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
  echo "PASS: $message\n";
};
$node = NULL;
$user = NULL;
$restore_template_tree = NULL;
try {
  $source = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => 'd38da97b-d941-4312-82b8-885e438b3801']);
  $alpha = reset($source);
  $check($alpha instanceof Node, 'Demo alpha exists');
  $node = $alpha->createDuplicate();
  $node->setTitle('Temporary two-slot integration check');
  $node->set('path', []);
  $node->save();
  $password = bin2hex(random_bytes(24));
  $user = User::create(['name' => 'slot-check-' . bin2hex(random_bytes(5)), 'status' => 1, 'pass' => $password, 'roles' => ['slot_demo_editor']]);
  $user->save();
  $client = new Client(['base_uri' => 'https://d11-next.ddev.site', 'cookies' => TRUE, 'http_errors' => FALSE, 'timeout' => 30]);
  $response = $client->post('/user/login?_format=json', ['json' => ['name' => $user->getAccountName(), 'pass' => $password]]);
  $check($response->getStatusCode() === 200, 'Temporary moderator can log in');
  $login = json_decode((string) $response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
  $headers = ['Accept' => 'application/json', 'X-CSRF-Token' => $login['csrf_token']];
  $url = '/canvas/api/v0/layout/node/' . $node->id();
  $get = static function () use ($client, $headers, $url, $check): array {
    $response = $client->get($url, ['headers' => $headers]);
    $body = (string) $response->getBody();
    if ($response->getStatusCode() !== 200) {
      throw new RuntimeException('Layout GET failed: ' . $response->getStatusCode() . ' ' . substr($body, 0, 1600));
    }
    return json_decode($body, TRUE, flags: JSON_THROW_ON_ERROR);
  };
  $layout = $get();
  $region_ids = array_column($layout['layout'], 'id');
  sort($region_ids);
  $check($region_ids === ['canvas_slot_additional', 'canvas_slot_main'], 'API exposes exactly two editable slot regions');
  $host = '6750b4b3-b6f3-43ee-a9f0-c39890a34401';
  $check(!isset($layout['model'][$host]), 'Template shell is absent from the editable model');
  $template_response = $client->get('/canvas/api/v0/layout-content-template/node.slot_demo.full/' . $node->id(), ['headers' => $headers]);
  $check($template_response->getStatusCode() === 403, 'Moderator cannot edit the shared content template');
  $post_body = static function (array $layout): array {
    return array_intersect_key($layout, array_flip(['layout', 'model', 'autoSaves', 'entity_form_fields'])) + ['clientInstanceId' => 'slot-demo-integration'];
  };
  $bad = $post_body($layout);
  $bad['layout'][] = ['nodeType' => 'region', 'id' => 'content', 'name' => 'Forbidden', 'components' => []];
  $response = $client->post($url, ['headers' => $headers, 'json' => $bad]);
  $check($response->getStatusCode() === 403, 'Server rejects writes outside the exposed slots');
  $before_template = ContentTemplate::load('node.slot_demo.full')->toArray();
  $before_sidebar = $node->get('canvas_slot_additional')->getValue();
  $main_uuid = array_column($layout['layout'], NULL, 'id')['canvas_slot_main']['components'][0]['uuid'];
  $body = $post_body($layout);
  $body['model'][$main_uuid]['resolved']['heading'] = 'Updated main slot through the API';
  $response = $client->post($url, ['headers' => $headers, 'json' => $body]);
  if ($response->getStatusCode() !== 200) {
    throw new RuntimeException('Layout POST failed: ' . $response->getStatusCode() . ' ' . substr((string) $response->getBody(), 0, 2000));
  }
  $draft = $get();
  $check($draft['model'][$main_uuid]['resolved']['heading'] === 'Updated main slot through the API', 'Main slot edits survive auto-save and reload');
  $aside_uuid = array_column($draft['layout'], NULL, 'id')['canvas_slot_additional']['components'][0]['uuid'];
  $check($draft['model'][$aside_uuid]['resolved']['heading'] === 'From the notebook', 'Editing the main slot preserves the additional slot');
  $body = $post_body($draft);
  $body['model'][$aside_uuid]['resolved']['heading'] = 'Updated additional slot through the API';
  $response = $client->post($url, ['headers' => $headers, 'json' => $body]);
  if ($response->getStatusCode() !== 200) {
    throw new RuntimeException('Second slot POST failed: ' . $response->getStatusCode() . ' ' . substr((string) $response->getBody(), 0, 2000));
  }
  $draft = $get();
  $check($draft['model'][$main_uuid]['resolved']['heading'] === 'Updated main slot through the API' && $draft['model'][$aside_uuid]['resolved']['heading'] === 'Updated additional slot through the API', 'Both slots retain independent edits');
  $response = $client->post('/canvas-headless/assertion?entity_type=node&entity=' . $node->id(), ['headers' => $headers]);
  $check($response->getStatusCode() === 200, 'Moderator can activate the Next draft preview');
  $assertion_data = json_decode((string) $response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
  $response = $client->get('https://d11-next.ddev.site:3000/api/draft', ['query' => ['assertion' => $assertion_data['assertion']]]);
  $preview_html = (string) $response->getBody();
  $check($response->getStatusCode() === 200 && str_contains($preview_html, 'Updated main slot through the API') && str_contains($preview_html, 'Updated additional slot through the API'), 'Next draft preview renders unsaved changes in both slots');
  $check(str_contains($preview_html, $host . '/main') && str_contains($preview_html, $host . '/supplementary'), 'Next preview supplies editor markers for both exposed slots');
  $pending_response = $client->get('/canvas/api/v0/auto-saves/pending', ['headers' => $headers]);
  $pending = json_decode((string) $pending_response->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
  $check(ContentTemplate::load('node.slot_demo.full')->toArray() === $before_template, 'Editing slots leaves the shared template unchanged');
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $storage->resetCache([$node->id()]);
  $stored = $storage->load($node->id());
  $check($stored->get('canvas_slot_additional')->getValue() === $before_sidebar, 'Unpublished slot edits do not change published content');
  $selected = array_filter($pending['data'], static fn(array $item): bool => $item['entity_type'] === 'node' && (string) $item['entity_id'] === (string) $node->id());
  $check(count($selected) === 1, 'Only the temporary test node is selected for publication');
  $response = $client->post('/canvas/api/v0/auto-saves/publish', ['headers' => $headers, 'json' => $selected]);
  if ($response->getStatusCode() !== 200) {
    throw new RuntimeException('Publish failed: ' . $response->getStatusCode() . ' ' . substr((string) $response->getBody(), 0, 2000));
  }
  $storage->resetCache([$node->id()]);
  $published = $storage->load($node->id());
  $check($published->get('canvas_slot_main')->get(0)->getInputs()['heading'] === 'Updated main slot through the API', 'Publication persists main slot changes');
  $check($published->get('canvas_slot_additional')->get(0)->getInputs()['heading'] === 'Updated additional slot through the API', 'Publication persists additional slot changes');
  $response = $client->get('https://d11-next.ddev.site:3000/node/' . $node->id());
  $html = (string) $response->getBody();
  $check($response->getStatusCode() === 200 && str_contains($html, 'Updated main slot through the API') && str_contains($html, 'Updated additional slot through the API'), 'Next renders both published slot changes');
  foreach (['alpha' => ['Good ideas start', 'Room to explore', 'From the notebook'], 'beta' => ['Small details.', 'The work behind the work', 'Stay curious']] as $slug => $expected) {
    $response = $client->get('https://d11-next.ddev.site:3000/slot-demo/' . $slug);
    $html = (string) $response->getBody();
    $check($response->getStatusCode() === 200, "Next serves $slug");
    foreach ($expected as $value) {
      $check(str_contains($html, $value), "$slug renders: $value");
    }
  }
  $template = ContentTemplate::load('node.slot_demo.full');
  $restore_template_tree = $template->get('component_tree');
  $changed_tree = $restore_template_tree;
  $changed_tree[$host]['inputs']['title'] = 'Shared template propagation check';
  $template->set('component_tree', $changed_tree)->save();
  foreach (['alpha', 'beta'] as $slug) {
    $response = $client->get('https://d11-next.ddev.site:3000/slot-demo/' . $slug);
    $check($response->getStatusCode() === 200 && str_contains((string) $response->getBody(), 'Shared template propagation check'), "Shared template change reaches $slug");
  }

}
finally {
  if ($restore_template_tree !== NULL) {
    ContentTemplate::load('node.slot_demo.full')->set('component_tree', $restore_template_tree)->save();
  }
  $node?->delete();
  $user?->delete();
}
