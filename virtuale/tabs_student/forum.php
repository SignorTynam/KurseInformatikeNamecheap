<?php
// Student — Forum (UI si Admin, me kufizimet e studentit)
/* Guard */
if (!isset($threads)) { $threads = []; }
if (!function_exists('h')) { function h(?string $s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); } }
$CSRF = $CSRF ?? ($_SESSION['csrf_token'] ?? ($_SESSION['csrf'] ?? ''));

/* Parsedown (safe) */
if (!isset($Parsedown)) {
  require_once __DIR__ . '/../lib/Parsedown.php';
  $Parsedown = new Parsedown();
  if (method_exists($Parsedown, 'setSafeMode')) { $Parsedown->setSafeMode(true); }
}

/* Stats (llogariten këtu nëse s’vijnë nga prindi) */
$cntAll = $cntAll ?? (int)count($threads);
$cntUnanswered = $cntUnanswered ?? (function($threads){
  $c = 0; foreach ($threads as $t){ if ((int)($t['replies_count'] ?? 0) === 0) $c++; } return $c;
})($threads);
$lastTs = null;
foreach ($threads as $t){ $ts = !empty($t['created_at']) ? strtotime((string)$t['created_at']) : null; if ($ts && ($lastTs===null || $ts>$lastTs)) $lastTs=$ts; }
?>

<div class="forum-wrap">
  <!-- Toolbar (si Admin) -->
  <div class="forum-toolbar">
    <div class="left">
      <div class="forum-stats d-flex align-items-center gap-2">
        <span class="chip"><i class="bi bi-chat-left-text me-1"></i><?= (int)$cntAll ?> tema</span>
        <span class="chip"><i class="bi bi-question-circle me-1"></i><?= (int)$cntUnanswered ?> pa përgj.</span>
        <?php if ($lastTs): ?>
          <span class="chip"><i class="bi bi-clock-history me-1"></i>Last: <?= date('d M, H:i', $lastTs) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div class="right">
      <div class="input-group">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input id="forumSearch" type="search" class="form-control" placeholder="Kërko në tema…">
      </div>

      <div class="btn-group" role="group" aria-label="Unanswered filter">
        <input type="checkbox" class="btn-check" id="onlyUnanswered">
        <label class="btn btn-outline-secondary" for="onlyUnanswered" title="Vetëm pa përgjigje"><i class="bi bi-slash-circle"></i></label>
      </div>

      <select id="sortSelect" class="form-select">
        <option value="latest">Më të rejat</option>
        <option value="replies">Më shumë përgjigje</option>
      </select>

      <!-- Temë e re (lejuar për student) -->
      <button class="btn btn-moodle" data-bs-toggle="modal" data-bs-target="#newThreadModal">
        <i class="bi bi-plus-lg me-1"></i>Temë e re
      </button>
    </div>
  </div>

  <!-- Lista e temave (karta + Markdown snippet) -->
  <?php if (!empty($threads)): ?>
    <div id="threadList" class="thread-list">
      <?php foreach ($threads as $t):
        $id   = (int)$t['id'];
        $url  = 'threads/thread_view.php?thread_id='.$id;
        $txt  = trim((string)($t['content'] ?? ''));
        $html = $Parsedown->text($txt); // safe HTML
        $repl = (int)($t['replies_count'] ?? 0);
        $ts   = !empty($t['created_at']) ? strtotime((string)$t['created_at']) : time();
      ?>
      <div class="thread-card"
           data-id="<?= $id ?>"
           data-replies="<?= $repl ?>"
           data-ts="<?= $ts ?>"
           data-title="<?= h($t['title'] ?? '') ?>"
           data-author="<?= h($t['full_name'] ?? '') ?>">
        <div class="actions">
          <button class="btn btn-sm btn-outline-secondary copyLinkBtn" data-href="<?= h($url) ?>" title="Kopjo linkun"><i class="bi bi-link-45deg"></i></button>
          <a class="btn btn-sm btn-outline-dark" href="<?= h($url) ?>" title="Hape"><i class="bi bi-box-arrow-up-right"></i></a>
        </div>
        <div class="d-flex justify-content-between align-items-start">
          <h6 class="title"><a class="text-decoration-none text-dark" href="<?= h($url) ?>"><?= h($t['title'] ?? '') ?></a></h6>
          <span class="badge text-bg-light"><i class="bi bi-reply me-1"></i><?= $repl ?></span>
        </div>
        <div class="md-snippet"><?= $html ?></div>
        <div class="meta">
          <span><i class="bi bi-person me-1"></i><?= h($t['full_name'] ?? '—') ?></span>
          <span><i class="bi bi-calendar me-1"></i><?= date('d M Y, H:i', $ts) ?></span>
          <?php if ($repl === 0): ?><span class="badge rounded-pill text-bg-warning text-dark">Pa përgjigje</span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="forum-empty">
      <i class="bi bi-chat-left-text display-6 d-block mb-2"></i>
      Nuk ka tema ende.
      <div class="mt-2">
        <button class="btn btn-moodle btn-sm" data-bs-toggle="modal" data-bs-target="#newThreadModal">
          <i class="bi bi-plus-lg me-1"></i>Krijo temën e parë
        </button>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- FAB: si te admin (pa veprime moderimi) -->
<div class="fab-dock" id="forumFabDock">
  <button class="fab-mini" data-action="new"      title="Temë e re"><i class="bi bi-plus-lg"></i></button>
  <button class="fab-mini" data-action="filter"   title="Filtro/Kërko"><i class="bi bi-funnel"></i></button>
  <button class="fab-mini" data-action="refresh"  title="Rifresko"><i class="bi bi-arrow-repeat"></i></button>
  <button class="fab-mini" data-action="top"      title="Shko lart"><i class="bi bi-arrow-up-short"></i></button>
</div>

<!-- Modal: New Thread (njësoj si më parë) -->
<div class="modal fade" id="newThreadModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="POST" action="threads/thread_create.php" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title">Temë e re në forum</h5>
        <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="course_id" value="<?= (int)($course_id ?? 0) ?>">
        <div class="mb-3">
          <label class="form-label">Titulli</label>
          <input class="form-control" name="title" required maxlength="255">
        </div>
        <div class="mb-3">
          <label class="form-label">Përshkrimi</label>
          <textarea class="form-control" name="content" rows="4" required placeholder="Përshkrim i detajuar i temës…"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Skedar (opsional)</label>
          <input type="file" class="form-control" name="attachment">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-moodle" type="submit"><i class="bi bi-send me-1"></i>Krijo</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  // ---- Toast fallback (nëse s’vjen nga prindi) ----
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
      const t = new bootstrap.Toast(el, { delay: 3500, autohide: true });
      t.show();
    };
  }

  // ---- Kërkim, filtër, renditje (njësoj si Admin) ----
  const searchInput = document.getElementById('forumSearch');
  const onlyUnanswered = document.getElementById('onlyUnanswered');
  const sortSelect = document.getElementById('sortSelect');
  const list = document.getElementById('threadList');
  let cards = list ? Array.from(list.querySelectorAll('.thread-card')) : [];

  function applyFilter(){
    const q = (searchInput?.value || '').toLowerCase().trim();
    const wantUn = !!onlyUnanswered?.checked;
    cards.forEach(c=>{
      const title = (c.getAttribute('data-title')||'').toLowerCase();
      const author = (c.getAttribute('data-author')||'').toLowerCase();
      const replies = parseInt(c.getAttribute('data-replies'),10) || 0;
      const match = (!q || title.includes(q) || author.includes(q));
      const okUn = (!wantUn || replies===0);
      c.style.display = (match && okUn) ? '' : 'none';
    });
  }

  function applySort(){
    if (!list) return;
    const how = sortSelect?.value || 'latest';
    const sorted = cards.slice().sort((a,b)=>{
      if (how==='replies'){
        return (parseInt(b.getAttribute('data-replies'))||0) - (parseInt(a.getAttribute('data-replies'))||0);
      }
      return (parseInt(b.getAttribute('data-ts'))||0) - (parseInt(a.getAttribute('data-ts'))||0);
    });
    sorted.forEach(el=> list.appendChild(el));
  }

  searchInput?.addEventListener('input', ()=>{ applyFilter(); });
  onlyUnanswered?.addEventListener('change', ()=>{ applyFilter(); showToast('info', onlyUnanswered.checked ? 'Po shfaqen vetëm temat pa përgjigje.' : 'Filtri i çaktivizuar.'); });
  sortSelect?.addEventListener('change', ()=>{ applySort(); showToast('success','Renditja u përditësua.'); });

  applySort(); // rendit fillimisht

  // ---- FAB actions ----
  document.querySelectorAll('#forumFabDock .fab-mini').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const act = btn.getAttribute('data-action');
      if (act==='new'){
        const modalEl = document.getElementById('newThreadModal');
        if (modalEl) new bootstrap.Modal(modalEl).show();
      } else if (act==='filter'){
        searchInput?.focus();
        showToast('info','Shkruaj për të filtruar temat.');
      } else if (act==='refresh'){
        location.reload();
      } else if (act==='top'){
        window.scrollTo({top:0, behavior:'smooth'});
      }
    });
  });

  // ---- Copy link ----
  document.querySelectorAll('.copyLinkBtn').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const href = btn.getAttribute('data-href') || '';
      try{
        await navigator.clipboard.writeText(location.origin.replace(/\/$/,'') + '/' + href.replace(/^\//,''));
        showToast('success','Linku i temës u kopjua.');
      }catch(e){
        showToast('danger','S’u kopjua linku.');
      }
    });
  });
})();
</script>
