<?php
/* -------------------------------------------
   tabs/course_payments.php  (FULL FILE)
   -------------------------------------------
   Kërkon: $pdo, $course_id, $_SESSION (për CSRF)
--------------------------------------------*/

if (!isset($pdo, $course_id)) { die('payments: missing scope'); }
$CSRF = $CSRF ?? ($_SESSION['csrf_token'] ?? ($_SESSION['csrf'] ?? ''));

/* ===== Metrikat ===== */
try {
  $stmtPayMetrics = $pdo->prepare("
    SELECT 
      COUNT(*) AS cnt_all,
      COALESCE(SUM(p.amount),0) AS sum_all,
      SUM(CASE WHEN p.payment_status='COMPLETED' THEN 1 ELSE 0 END) AS cnt_completed,
      COALESCE(SUM(CASE WHEN p.payment_status='COMPLETED' THEN p.amount ELSE 0 END),0) AS sum_completed,
      SUM(CASE WHEN p.payment_status='FAILED' THEN 1 ELSE 0 END) AS cnt_failed,
      COALESCE(SUM(CASE WHEN p.payment_status='FAILED' THEN p.amount ELSE 0 END),0) AS sum_failed
    FROM payments p
    WHERE p.course_id = ?
  ");
  $stmtPayMetrics->execute([$course_id]);
  $payMetrics = $stmtPayMetrics->fetch(PDO::FETCH_ASSOC) ?: [
    'cnt_all'=>0,'sum_all'=>0,'cnt_completed'=>0,'sum_completed'=>0,'cnt_failed'=>0,'sum_failed'=>0
  ];
} catch (PDOException $e) {
  $payMetrics = ['cnt_all'=>0,'sum_all'=>0,'cnt_completed'=>0,'sum_completed'=>0,'cnt_failed'=>0,'sum_failed'=>0];
}

/* ===== Lista pagesave ===== */
try {
  $stmtPayList = $pdo->prepare("
    SELECT p.*, u.full_name
    FROM payments p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.course_id = ?
    ORDER BY p.payment_date DESC, p.id DESC
  ");
  $stmtPayList->execute([$course_id]);
  $paymentsList = $stmtPayList->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $paymentsList = []; }

/* ===== Pjesëmarrës për <select> në modalet e editimit ===== */
try {
  $stmtEnroll = $pdo->prepare("
    SELECT e.user_id, u.full_name
    FROM enroll e
    LEFT JOIN users u ON u.id=e.user_id
    WHERE e.course_id=?
    ORDER BY u.full_name
  ");
  $stmtEnroll->execute([$course_id]);
  $participants = $stmtEnroll->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e){ $participants=[]; }
?>

<div class="cardx">

  <!-- Toolbar -->
  <div class="pay-toolbar mb-3">
    <div class="chips">
      <span class="chip"><i class="bi bi-receipt me-1"></i><?= (int)($payMetrics['cnt_all'] ?? 0) ?> pagesa</span>
      <span class="chip"><i class="bi bi-currency-euro me-1"></i><?= number_format((float)($payMetrics['sum_all'] ?? 0), 2) ?>€ gjithsej</span>
      <span class="chip"><i class="bi bi-check2-circle me-1"></i><?= (int)($payMetrics['cnt_completed'] ?? 0) ?> OK • <?= number_format((float)($payMetrics['sum_completed'] ?? 0),2) ?>€</span>
      <span class="chip"><i class="bi bi-x-circle me-1"></i><?= (int)($payMetrics['cnt_failed'] ?? 0) ?> FAIL</span>
    </div>

    <div class="actions">
      <div class="input-group searchbox">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input id="paySearch" type="search" class="form-control" placeholder="Kërko sipas emrit…">
        <button id="clearPaySearch" class="btn btn-outline-secondary d-none" type="button" title="Pastro"><i class="bi bi-x-lg"></i></button>
      </div>

      <select id="statusFilter" class="form-select" title="Filtro sipas statusit">
        <option value="ALL">Të gjitha</option>
        <option value="COMPLETED">Vetëm COMPLETED</option>
        <option value="FAILED">Vetëm FAILED</option>
      </select>

      <select id="paySort" class="form-select" title="Rendit">
        <option value="date_desc">Më të rejat</option>
        <option value="date_asc">Më të vjetrat</option>
        <option value="amount_desc">Shuma (zbritës)</option>
        <option value="amount_asc">Shuma (ngjitës)</option>
        <option value="name_asc">Emri (A–Z)</option>
      </select>

      <button class="btn btn-moodle" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
        <i class="bi bi-plus-lg me-1"></i> Shto pagesë
      </button>
    </div>
  </div>

  <!-- Tabela -->
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Student</th>
          <th>Shuma</th>
          <th>Statusi</th>
          <th>Data</th>
          <th class="text-end">Veprime</th>
        </tr>
      </thead>
      <tbody id="payRows">
        <?php if (!$paymentsList): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">S’ka pagesa për këtë kurs.</td></tr>
        <?php else: ?>
          <?php
            $editModals = ''; // grumbulloj modalet këtu, do t’i shtojmë pas tabelës
            foreach ($paymentsList as $p):
              $status = $p['payment_status'] ?? 'FAILED';
              $badge  = $status==='COMPLETED' ? 'success' : 'secondary';
              $pid    = (int)$p['id'];
              $ts     = $p['payment_date'] ? strtotime((string)$p['payment_date']) : 0;
              $amt    = (float)($p['amount'] ?? 0);
              $name   = (string)($p['full_name'] ?? '—');
          ?>
          <tr class="pay-row"
              data-name="<?= htmlspecialchars(mb_strtolower($name,'UTF-8'),ENT_QUOTES) ?>"
              data-status="<?= htmlspecialchars($status,ENT_QUOTES) ?>"
              data-amount="<?= htmlspecialchars(number_format($amt,2,'.',''),ENT_QUOTES) ?>"
              data-ts="<?= (int)$ts ?>">
            <td><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($name,ENT_QUOTES) ?></td>
            <td><?= number_format($amt, 2) ?>€</td>
            <td><span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars($status,ENT_QUOTES) ?></span></td>
            <td><?= $ts ? date('d M Y, H:i', $ts) : '—' ?></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editPaymentModal<?= $pid ?>"><i class="bi bi-pencil"></i></button>
              <form class="d-inline" method="POST" action="process_payment.php" onsubmit="return confirm('Fshi pagesën?');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF,ENT_QUOTES) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="payment_id" value="<?= $pid ?>">
                <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php
            // —— MODAL (shto në buffer, JO brenda tabelës)
            ob_start(); ?>
            <div class="modal fade" id="editPaymentModal<?= $pid ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog">
                <form class="modal-content" method="POST" action="process_payment.php">
                  <div class="modal-header">
                    <h5 class="modal-title">Modifiko pagesën</h5>
                    <button class="btn-close" data-bs-dismiss="modal" type="button"></button>
                  </div>
                  <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF,ENT_QUOTES) ?>">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="payment_id" value="<?= $pid ?>">
                    <input type="hidden" name="course_id" value="<?= (int)$course_id ?>">

                    <div class="mb-3">
                      <label class="form-label">Studenti</label>
                      <select class="form-select" name="user_id" required>
                        <?php foreach ($participants as $st): ?>
                          <option value="<?= (int)$st['user_id'] ?>" <?= ((int)$st['user_id']===(int)$p['user_id'])?'selected':'' ?>>
                            <?= htmlspecialchars($st['full_name'],ENT_QUOTES) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="mb-3">
                      <label class="form-label">Shuma (€)</label>
                      <input class="form-control" type="number" name="amount" min="0" step="0.01"
                             value="<?= htmlspecialchars((string)$p['amount'],ENT_QUOTES) ?>" required>
                    </div>

                    <div class="mb-3">
                      <label class="form-label">Statusi</label>
                      <select class="form-select" name="payment_status">
                        <option value="COMPLETED" <?= ($p['payment_status']==='COMPLETED')?'selected':'' ?>>COMPLETED</option>
                        <option value="FAILED"     <?= ($p['payment_status']!=='COMPLETED')?'selected':'' ?>>FAILED</option>
                      </select>
                    </div>

                    <div class="mb-3">
                      <label class="form-label">Data pagesës</label>
                      <input class="form-control" type="datetime-local" name="payment_date"
                             value="<?= $p['payment_date'] ? date('Y-m-d\TH:i', strtotime((string)$p['payment_date'])) : '' ?>">
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Mbyll</button>
                    <button class="btn btn-moodle" type="submit"><i class="bi bi-save me-1"></i>Ruaj</button>
                  </div>
                </form>
              </div>
            </div>
            <?php
            $editModals .= ob_get_clean();
            endforeach; // foreach payments
          ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div><!-- /.table-responsive -->

  <!-- Montimi i modalëve të editimit JASHTË tabelës (s’klipohen) -->
  <?= $editModals ?? '' ?>

  <div class="small text-muted mt-2">Shënim: Pagesat janë të pavarura nga elementet e kursit.</div>
</div>

<!-- FABs -->
<div id="paymentsFabDock" aria-label="payments actions">
  <button class="fab-mini" data-action="add"      title="Shto pagesë"><i class="bi bi-plus-lg"></i></button>
  <button class="fab-mini" data-action="refresh"  title="Rifresko"><i class="bi bi-arrow-repeat"></i></button>
  <button class="fab-mini" data-action="top"      title="Shko lart"><i class="bi bi-arrow-up-short"></i></button>
</div>

<script>
(function(){
  // ---- Toast fallback (nëse s'është injektuar nga parent) ----
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

  const q = document.getElementById('paySearch');
  const clearQ = document.getElementById('clearPaySearch');
  const statusSel = document.getElementById('statusFilter');
  const sortSel = document.getElementById('paySort');
  const tbody = document.getElementById('payRows');

  function rows(){ return Array.from(tbody?.querySelectorAll('tr.pay-row') || []); }

  function updateClearBtn(){
    clearQ?.classList.toggle('d-none', !(q && q.value && q.value.trim().length));
  }

  function applyFilter(){
    const s = (q?.value || '').toLowerCase().trim();
    const status = statusSel?.value || 'ALL';
    rows().forEach(r=>{
      const nm = (r.getAttribute('data-name') || '');
      const st = (r.getAttribute('data-status') || '');
      const okText = !s || nm.includes(s);
      const okStatus = (status==='ALL') || (st===status);
      r.style.display = (okText && okStatus) ? '' : 'none';
    });
  }

  function applySort(){
    const how = sortSel?.value || 'date_desc';
    const arr = rows();
    arr.sort((a,b)=>{
      const ta = parseInt(a.getAttribute('data-ts')||'0',10);
      const tb = parseInt(b.getAttribute('data-ts')||'0',10);
      const aa = parseFloat(a.getAttribute('data-amount')||'0');
      const ab = parseFloat(b.getAttribute('data-amount')||'0');
      const na = (a.getAttribute('data-name')||'');
      const nb = (b.getAttribute('data-name')||'');
      switch(how){
        case 'date_asc':   return ta - tb;
        case 'amount_desc':return ab - aa;
        case 'amount_asc': return aa - ab;
        case 'name_asc':   return na.localeCompare(nb);
        default:           return tb - ta; // date_desc
      }
    });
    arr.forEach(tr => tbody.appendChild(tr));
  }

  q?.addEventListener('input', ()=>{ updateClearBtn(); applyFilter(); });
  clearQ?.addEventListener('click', ()=>{ q.value=''; updateClearBtn(); applyFilter(); q.focus(); });
  statusSel?.addEventListener('change', ()=>{ applyFilter(); showToast('success','Filtri u përditësua.'); });
  sortSel?.addEventListener('change', ()=>{ applySort(); showToast('success','Renditja u përditësua.'); });

  updateClearBtn();
  applySort();

  // FABs
  document.getElementById('paymentsFabDock')?.addEventListener('click', (e)=>{
    const b = e.target.closest('.fab-mini'); if(!b) return;
    const act = b.getAttribute('data-action');
    if (act==='add'){
      const m = document.getElementById('addPaymentModal');
      if (m) bootstrap.Modal.getOrCreateInstance(m).show();
    } else if (act==='refresh'){
      location.reload();
    } else if (act==='top'){
      window.scrollTo({top:0, behavior:'smooth'});
    }
  });
})();
</script>
