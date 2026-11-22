<?php

/**
 * Applies the repository patch to the pinned Canvas source, idempotently.
 *
 * Composer runs this after install/update. Build the editor afterwards with
 * scripts/build-canvas-prototype.sh.
 */

$project = dirname(__DIR__);
$canvas = $project . '/web/modules/contrib/canvas';
$patch = $project . '/patches/canvas-slot-restrictions.patch';
if (!is_dir($canvas) || !is_file($patch)) {
  throw new RuntimeException('Canvas sources and the slot restrictions patch must exist.');
}

$run = static function (array $options) use ($canvas, $patch): array {
  $command = [
    'patch', '--batch', '--no-backup-if-mismatch', '--strip=1',
    '--directory=' . $canvas, '--input=' . $patch, ...$options,
  ];
  $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) {
    throw new RuntimeException('Cannot start patch.');
  }
  fclose($pipes[0]);
  $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  return [proc_close($process), $output];
};

[$status] = $run(['--dry-run', '--forward']);
if ($status !== 0) {
  [$reverse_status] = $run(['--dry-run', '--reverse']);
  if ($reverse_status === 0) {
    echo "Canvas slot restrictions patch is already applied.\n";
    exit(0);
  }
  throw new RuntimeException('Canvas differs from the supported pinned source. The slot restrictions patch cannot be applied cleanly.');
}
[$status, $output] = $run(['--forward']);
if ($status !== 0) {
  throw new RuntimeException($output);
}
echo "Applied Canvas slot restrictions patch. Rebuild the Canvas editor before use.\n";
