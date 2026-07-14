<?php $rows = $data['content'] ?? []; $withHeadings = (bool) ($data['withHeadings'] ?? false); ?>
<div class="km-block km-block-table-wrap" role="region" aria-label="Tabelë" tabindex="0"><table class="km-block-table">
<?php foreach ($rows as $rowIndex => $row): ?>
  <tr><?php foreach ($row as $cell): $tag = $withHeadings && $rowIndex === 0 ? 'th' : 'td'; ?><<?= $tag ?>><?= $sanitizer->inline((string) $cell) ?></<?= $tag ?>><?php endforeach; ?></tr>
<?php endforeach; ?>
</table></div>
