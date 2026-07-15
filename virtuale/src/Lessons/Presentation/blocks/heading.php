<?php $level = in_array((int) ($data['level'] ?? 2), [2, 3, 4], true) ? (int) $data['level'] : 2; ?>
<h<?= $level ?> id="<?= htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') ?>" class="km-block km-block-heading"><?= $sanitizer->inline((string) ($data['text'] ?? '')) ?></h<?= $level ?>>
