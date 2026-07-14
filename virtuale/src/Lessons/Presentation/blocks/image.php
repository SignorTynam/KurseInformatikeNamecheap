<?php
$classes = ['km-block', 'km-block-image'];
if (!empty($data['withBorder'])) $classes[] = 'with-border';
if (!empty($data['withBackground'])) $classes[] = 'with-background';
if (!empty($data['stretched'])) $classes[] = 'is-stretched';
?>
<figure class="<?= implode(' ', $classes) ?>">
  <img src="<?= htmlspecialchars($mediaUrl . (int) ($data['mediaId'] ?? 0), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($data['alt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" loading="lazy">
  <?php if (trim((string) ($data['caption'] ?? '')) !== ''): ?><figcaption><?= htmlspecialchars((string) $data['caption'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></figcaption><?php endif; ?>
</figure>
