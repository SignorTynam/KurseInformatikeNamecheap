<?php $variant = in_array(($data['variant'] ?? ''), ['info','success','warning','danger','tip'], true) ? $data['variant'] : 'info'; ?>
<aside class="km-block km-block-callout km-callout-<?= htmlspecialchars((string) $variant, ENT_QUOTES, 'UTF-8') ?>">
  <?php if (($data['title'] ?? '') !== ''): ?><strong><?= htmlspecialchars((string) $data['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><?php endif; ?>
  <div><?= $sanitizer->inline((string) ($data['text'] ?? '')) ?></div>
</aside>
