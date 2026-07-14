<blockquote class="km-block km-block-quote text-<?= htmlspecialchars((string) ($data['alignment'] ?? 'left'), ENT_QUOTES, 'UTF-8') ?>">
  <p><?= $sanitizer->inline((string) ($data['text'] ?? '')) ?></p>
  <?php if (($data['caption'] ?? '') !== ''): ?><cite><?= htmlspecialchars((string) $data['caption'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></cite><?php endif; ?>
</blockquote>
