<?php $style = (string) ($data['style'] ?? 'unordered'); $tag = $style === 'ordered' ? 'ol' : 'ul'; ?>
<<?= $tag ?> class="km-block km-block-list<?= $style === 'checklist' ? ' km-block-checklist' : '' ?>">
<?php foreach (($data['items'] ?? []) as $item): ?>
  <li><?= $style === 'checklist' ? '<span aria-hidden="true">☐</span> ' : '' ?><?= $sanitizer->inline((string) $item) ?></li>
<?php endforeach; ?>
</<?= $tag ?>>
