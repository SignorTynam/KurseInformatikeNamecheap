<?php
/* Guard */
if (!isset($pdo, $course_id)) { die('forum: missing scope'); }
$CSRF = $CSRF ?? ($_SESSION['csrf_token'] ?? ($_SESSION['csrf'] ?? ''));

/* Markdown (server-side) */
if (!isset($Parsedown)) {
  require_once __DIR__ . '/../lib/Parsedown.php';
  $Parsedown = new Parsedown();
  if (method_exists($Parsedown, 'setSafeMode')) { $Parsedown->setSafeMode(true); }
}

/* Query: threads per kurs */
try {
  $stmtThreads = $pdo->prepare("
    SELECT t.id, t.title, t.content, t.created_at,
           u.full_name,
           (SELECT COUNT(*) FROM thread_replies r WHERE r.thread_id = t.id) AS replies_count
    FROM threads t
    JOIN users u ON u.id = t.user_id
    WHERE t.course_id = ?
    ORDER BY t.created_at DESC
    LIMIT 50
  ");
  $stmtThreads->execute([$course_id]);
  $threads = $stmtThreads->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $threads=[]; }

/* Stats */
try {
  $stmtCntAll = $pdo->prepare("SELECT COUNT(*) FROM threads WHERE course_id=?");
  $stmtCntAll->execute([$course_id]); $cntAll = (int)$stmtCntAll->fetchColumn();
} catch (PDOException $e){ $cntAll = (int)count($threads); }

try {
  $stmtCntUn = $pdo->prepare("
    SELECT COUNT(*) FROM threads t
    WHERE t.course_id=? AND NOT EXISTS (SELECT 1 FROM thread_replies r WHERE r.thread_id=t.id)");
  $stmtCntUn->execute([$course_id]); $cntUnanswered = (int)$stmtCntUn->fetchColumn();
} catch (PDOException $e){ $cntUnanswered = 0; }

$lastTs = !empty($threads[0]['created_at']) ? strtotime($threads[0]['created_at']) : null;
?>

<div class="forum-wrap">

  <!-- Toolbar -->
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

      <!-- Modal ekzistues te course_details.php: #newThreadModal -->
      <button class="btn btn-moodle" data-bs-toggle="modal" data-bs-target="#newThreadModal">
        <i class="bi bi-plus-lg me-1"></i>Temë e re
      </button>
    </div>
  </div>

  <!-- Lista -->
  <?php if (!empty($threads)): ?>
    <div id="threadList" class="thread-list">
      <?php foreach ($threads as $t):
        $id   = (int)$t['id'];
        $url  = 'threads/thread_view.php?thread_id='.$id;
        $txt  = trim((string)$t['content']);
        /* Markdown -> HTML (safe) */
        $html = $Parsedown->text($txt);
        $repl = (int)$t['replies_count'];
        $ts   = strtotime((string)$t['created_at']);
      ?>
      <div class="thread-card"
           data-id="<?= $id ?>"
           data-replies="<?= $repl ?>"
           data-ts="<?= $ts ?>"
           data-title="<?= htmlspecialchars($t['title'],ENT_QUOTES) ?>"
           data-author="<?= htmlspecialchars($t['full_name'] ?? '',ENT_QUOTES) ?>">
        <div class="actions">
          <button class="btn btn-sm btn-outline-secondary copyLinkBtn" data-href="<?= htmlspecialchars($url,ENT_QUOTES) ?>" title="Kopjo linkun"><i class="bi bi-link-45deg"></i></button>
          <a class="btn btn-sm btn-outline-dark" href="<?= htmlspecialchars($url,ENT_QUOTES) ?>" title="Hape"><i class="bi bi-box-arrow-up-right"></i></a>
        </div>
        <div class="d-flex justify-content-between align-items-start">
          <h6 class="title"><a class="text-decoration-none text-dark" href="<?= htmlspecialchars($url,ENT_QUOTES) ?>"><?= htmlspecialchars($t['title'],ENT_QUOTES) ?></a></h6>
          <span class="badge text-bg-light"><i class="bi bi-reply me-1"></i><?= $repl ?></span>
        </div>

        <!-- SNIPPET me Markdown (HTML i sigurt), i klipuar me CSS -->
        <div class="md-snippet"><?= $html ?></div>

        <div class="meta">
          <span><i class="bi bi-person me-1"></i><?= htmlspecialchars($t['full_name'] ?? '—',ENT_QUOTES) ?></span>
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

<!-- FAB: gjithmonë të dukshme -->
<div class="fab-dock" id="forumFabDock">
  <button class="fab-mini" data-action="new"      title="Temë e re"><i class="bi bi-plus-lg"></i></button>
  <button class="fab-mini" data-action="filter"   title="Filtro/Kërko"><i class="bi bi-funnel"></i></button>
  <button class="fab-mini" data-action="refresh"  title="Rifresko"><i class="bi bi-arrow-repeat"></i></button>
  <button class="fab-mini" data-action="top"      title="Shko lart"><i class="bi bi-arrow-up-short"></i></button>
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
      const t = new bootstrap.Toast(el, { delay: 3500, autohide: true });
      t.show();
    };
  }

  // ---- Kërkim, filtër, renditje ----
  const searchInput = document.getElementById('forumSearch');
  const onlyUnanswered = document.getElementById('onlyUnanswered');
  const sortSelect = document.getElementById('sortSelect');
  const list = document.getElementById('threadList');
  let cards = list ? Array.from(list.querySelectorAll('.thread-card')) : [];

  function applyFilter(){
    const q = (searchInput?.value || '').toLowerCase().trim();
    const wantUn = onlyUnanswered?.checked;
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
      const href = btn.getAttribute('data-href');
      try{
        await navigator.clipboard.writeText(location.origin + '/' + href.replace(/^\//,''));
        showToast('success','Linku i temës u kopjua.');
      }catch(e){
        showToast('danger','S’u kopjua linku.');
      }
    });
  });
})();
</script>
