<?php
// tabs_student/people.php — Modern UI (shared styles)
// Pret: $participants (enroll + full_name)
$participants = is_array($participants ?? null) ? $participants : [];
$total = count($participants);
?>

<div class="people-wrap">
  <div class="people-toolbar">
    <div class="stats">
      <span class="chip"><i class="bi bi-people me-1"></i><?= (int)$total ?> pjesëmarrës</span>
    </div>

    <div class="searchbox input-group">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input id="stPeopleSearch" type="search" class="form-control" placeholder="Kërko me emër…" autocomplete="off">
      <button class="btn btn-outline-secondary" id="stPeopleClear" type="button" title="Pastro"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>

  <div id="stPeopleGrid" class="people-grid">
    <?php if ($participants): foreach ($participants as $p):
      $name = (string)($p['full_name'] ?? '—');
      $initial = $name !== '' ? mb_substr($name, 0, 1, 'UTF-8') : '—';
      $enr = !empty($p['enrolled_at']) ? date('M Y', strtotime((string)$p['enrolled_at'])) : '—';
    ?>
      <div class="person-card" data-name="<?= htmlspecialchars(mb_strtolower($name,'UTF-8'), ENT_QUOTES) ?>">
        <div class="d-flex align-items-center gap-3">
          <div class="avatar"><?= h($initial) ?></div>
          <div class="person-meta">
            <h6 class="mb-0 fw-bold"><?= h($name) ?></h6>
            <small class="text-muted">Regjistruar: <?= h($enr) ?></small>
          </div>
        </div>
      </div>
    <?php endforeach; else: ?>
      <div class="text-center py-4 text-muted">
        <i class="bi bi-people display-6 d-block mb-2"></i>
        Nuk ka pjesëmarrës.
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  const q = document.getElementById('stPeopleSearch');
  const clearBtn = document.getElementById('stPeopleClear');
  const grid = document.getElementById('stPeopleGrid');
  if (!q || !grid) return;

  const cards = Array.from(grid.querySelectorAll('.person-card'));
  function applyFilter(){
    const s = (q.value || '').toLowerCase().trim();
    cards.forEach(c => {
      const name = (c.getAttribute('data-name') || '');
      c.style.display = (!s || name.includes(s)) ? '' : 'none';
    });
  }
  q.addEventListener('input', applyFilter);
  clearBtn?.addEventListener('click', ()=>{ q.value=''; applyFilter(); q.focus(); });
})();
</script>
