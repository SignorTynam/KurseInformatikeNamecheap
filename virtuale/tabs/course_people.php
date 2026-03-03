<?php
try {
  $stmtEnroll = $pdo->prepare("
    SELECT e.*, u.full_name, u.email, u.id AS uid, e.user_id
    FROM enroll e
    LEFT JOIN users u ON e.user_id = u.id
    WHERE e.course_id = ?
    ORDER BY u.full_name
  ");
  $stmtEnroll->execute([$course_id]);
  $participants = $stmtEnroll->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $participants = []; }

/* Emailo të gjithëve (mailto bcc) */
$emails = array_filter(array_map(fn($r)=> trim((string)($r['email'] ?? '')), $participants));
$mailto_all = rawurlencode(implode(',', $emails));
$total = count($participants);
?>

<div class="people-wrap">
  <!-- Toolbar -->
  <div class="people-toolbar">
    <div class="stats">
      <span class="chip"><i class="bi bi-people me-1"></i><?= (int)$total ?> pjesëmarrës</span>
      <?php if ($total): ?>
        <span class="chip"><i class="bi bi-envelope me-1"></i><?= count($emails) ?> me email</span>
      <?php endif; ?>
    </div>
    <div class="searchbox input-group">
      <span class="input-group-text"><i class="bi bi-search"></i></span>
      <input id="peopleSearch" type="search" class="form-control" placeholder="Kërko me emër ose email…">
      <button class="btn btn-outline-secondary" id="clearSearch" type="button"><i class="bi bi-x-lg"></i></button>
    </div>
  </div>

  <!-- Grid -->
  <div id="peopleGrid" class="people-grid">
    <?php if ($participants): foreach ($participants as $p): 
      $initial = htmlspecialchars(mb_substr((string)$p['full_name'],0,1,'UTF-8'),ENT_QUOTES);
      $name = htmlspecialchars((string)$p['full_name'],ENT_QUOTES);
      $email = htmlspecialchars((string)($p['email'] ?? ''),ENT_QUOTES);
      $enr = !empty($p['enrolled_at']) ? date('M Y', strtotime((string)$p['enrolled_at'])) : '—';
      $uid = (int)($p['user_id'] ?? $p['uid'] ?? 0);
    ?>
    <div class="person-card" data-name="<?= strtolower($name) ?>" data-email="<?= strtolower($email) ?>">
      <div class="card-actions dropdown">
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-three-dots-vertical"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php if ($email): ?>
            <li><a class="dropdown-item" href="mailto:<?= $email ?>"><i class="bi bi-envelope me-2"></i>Email</a></li>
            <li><button class="dropdown-item copyOneEmail" data-email="<?= $email ?>"><i class="bi bi-clipboard me-2"></i>Kopjo email</button></li>
            <li><hr class="dropdown-divider"></li>
          <?php endif; ?>
          <li>
            <form method="POST" action="admin/delete_student.php" onsubmit="return confirm('Të hiqet <?= $name ?> nga ky kurs?');">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF,ENT_QUOTES) ?>">
              <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
              <input type="hidden" name="student_id" value="<?= $uid ?>">
              <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hiq nga kursi</button>
            </form>
          </li>
        </ul>
      </div>

      <div class="d-flex align-items-center gap-3">
        <div class="avatar"><?= $initial ?></div>
        <div class="person-meta">
          <h6 class="mb-0"><?= $name ?></h6>
          <small class="d-block"><?= $email ?: '<span class="text-muted">—</span>' ?></small>
          <small class="text-muted">Regjistruar: <?= $enr ?></small>
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

<!-- FAB Dock (bottom-right) -->
<div id="peopleFabDock" aria-label="people actions">
  <button class="fab-mini" data-action="add" title="Shto student"><i class="bi bi-person-plus"></i></button>
  <?php if (!empty($emails)): ?>
    <a class="fab-mini" data-action="emailall" title="Email të gjithëve"
       href="mailto:?bcc=<?= $mailto_all ?>&subject=<?= rawurlencode(($course['title'] ?? 'Kurs').' — njoftim') ?>">
      <i class="bi bi-envelope-paper"></i>
    </a>
    <button class="fab-mini" data-action="copyall" title="Kopjo të gjithë email-at"><i class="bi bi-clipboard-check"></i></button>
  <?php endif; ?>
  <button class="fab-mini" data-action="top" title="Shko lart"><i class="bi bi-arrow-up-short"></i></button>
</div>

<script>
(function(){
  // ---- Toast fallback (nëse s’është injektuar nga parent) ----
  if (!window.showToast){
    window.toastIcon = function(type){
      if (type==='success') return '<i class="fa-solid fa-circle-check me-2"></i>';
      if (type==='danger' || type==='error') return '<i class="fa-solid fa-triangle-exclamation me-2"></i>';
      if (type==='warning') return '<i class="fa-solid fa-circle-exclamation me-2"></i>';
      return '<i class="fa-solid fa-circle-info me-2"></i>';
    };
    window.showToast = function(type, msg){
      let zone = document.getElementById('toastZone');
      if (!zone){
        zone = document.createElement('div'); zone.id='toastZone';
        zone.setAttribute('aria-live','polite'); zone.setAttribute('aria-atomic','true');
        document.body.appendChild(zone);
      }
      const el = document.createElement('div');
      el.className = 'toast kurse align-items-center';
      el.setAttribute('role','alert'); el.setAttribute('aria-live','assertive'); el.setAttribute('aria-atomic','true');
      el.innerHTML = `
        <div class="toast-header">
          <strong class="me-auto d-flex align-items-center">${toastIcon(type)} Njoftim</strong>
          <small class="text-white-50">tani</small>
          <button type="button" class="btn-close ms-2 mb-1" data-bs-dismiss="toast" aria-label="Mbyll"></button>
        </div>
        <div class="toast-body">${msg}</div>`;
      zone.appendChild(el);
      new bootstrap.Toast(el, { delay: 3200, autohide: true }).show();
    };
  }

  // ---- Kërkimi (live filter) ----
  const q = document.getElementById('peopleSearch');
  const clearBtn = document.getElementById('clearSearch');
  const grid = document.getElementById('peopleGrid');
  const cards = grid ? Array.from(grid.querySelectorAll('.person-card')) : [];

  function applyFilter(){
    const s = (q?.value || '').toLowerCase().trim();
    let shown = 0;
    cards.forEach(c=>{
      const name = (c.getAttribute('data-name')||'');
      const email = (c.getAttribute('data-email')||'');
      const ok = !s || name.includes(s) || email.includes(s);
      c.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    showToast('info', s ? ('Po shfaqen ' + shown + ' rezultate.') : 'Filtri i çaktivizuar.');
  }
  q?.addEventListener('input', ()=> applyFilter());
  clearBtn?.addEventListener('click', ()=>{ if(q){ q.value=''; applyFilter(); q.focus(); } });

  // ---- Kopjo email (një përdorues) ----
  document.querySelectorAll('.copyOneEmail').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const em = btn.getAttribute('data-email'); if(!em) return;
      try{ await navigator.clipboard.writeText(em); showToast('success','Email-i u kopjua.'); }
      catch{ showToast('danger','S’u kopjua email-i.'); }
    });
  });

  // ---- FAB veprime ----
  const dock = document.getElementById('peopleFabDock');
  dock?.addEventListener('click', async (e)=>{
    const btn = e.target.closest('.fab-mini'); if(!btn) return;
    const act = btn.getAttribute('data-action');

    if (act==='add'){
      const modalEl = document.getElementById('addStudentModal');
      if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
      else showToast('warning','Modal #addStudentModal s’u gjet.');
    }
    else if (act==='copyall'){
      const all = <?= json_encode($emails) ?>;
      if (!all.length){ showToast('warning','S’ka email-e për kopjim.'); return; }
      try{
        await navigator.clipboard.writeText(all.join(', '));
        showToast('success', 'U kopjuan '+all.length+' email-e.');
      }catch{ showToast('danger','S’u kopjuan email-et.'); }
    }
    else if (act==='top'){
      window.scrollTo({top:0, behavior:'smooth'});
    }
  });
})();
</script>
