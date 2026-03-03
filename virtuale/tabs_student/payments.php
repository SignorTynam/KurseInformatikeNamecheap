<?php
// tabs_student/payments.php — Modern UI (student)
// Pret: $payments, $paidSum, $paidCount, $failedCount
$payments = is_array($payments ?? null) ? $payments : [];
$paidSum = (float)($paidSum ?? 0);
$paidCount = (int)($paidCount ?? 0);
$failedCount = (int)($failedCount ?? 0);
$totalCount = count($payments);
?>

<div class="cardx">
  <div class="pay-toolbar mb-3">
    <div class="chips">
      <span class="chip"><i class="bi bi-receipt me-1"></i><?= (int)$totalCount ?> pagesa</span>
      <span class="chip"><i class="bi bi-currency-euro me-1"></i><?= number_format($paidSum, 2) ?> EUR gjithsej</span>
      <span class="chip"><i class="bi bi-check2-circle me-1"></i><?= (int)$paidCount ?> suksesshme</span>
      <?php if ($failedCount): ?><span class="chip"><i class="bi bi-x-circle me-1"></i><?= (int)$failedCount ?> të dështuara</span><?php endif; ?>
    </div>

    <div class="actions">
      <div class="input-group searchbox">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input id="stPaySearch" type="search" class="form-control" placeholder="Kërko sipas elementit…" autocomplete="off">
        <button id="stClearPaySearch" class="btn btn-outline-secondary d-none" type="button" title="Pastro"><i class="bi bi-x-lg"></i></button>
      </div>

      <select id="stStatusFilter" class="form-select" title="Filtro sipas statusit">
        <option value="ALL">Të gjitha</option>
        <option value="COMPLETED">Vetëm COMPLETED</option>
        <option value="FAILED">Vetëm FAILED</option>
      </select>

      <select id="stPaySort" class="form-select" title="Rendit">
        <option value="date_desc">Më të rejat</option>
        <option value="date_asc">Më të vjetrat</option>
        <option value="amount_desc">Shuma (zbritës)</option>
        <option value="amount_asc">Shuma (ngjitës)</option>
        <option value="name_asc">Elementi (A–Z)</option>
      </select>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th>Element</th>
          <th>Statusi</th>
          <th>Shuma</th>
          <th>Data</th>
        </tr>
      </thead>
      <tbody id="stPayRows">
        <?php if ($payments): ?>
          <?php foreach ($payments as $p):
            $st = (string)($p['payment_status'] ?? '');
            $cls = $st === 'COMPLETED' ? 'text-bg-success' : ($st === 'FAILED' ? 'text-bg-danger' : 'text-bg-warning text-dark');
            $amt = (float)($p['amount'] ?? 0);
            $ts  = !empty($p['payment_date']) ? strtotime((string)$p['payment_date']) : 0;
            $name = (string)($p['lesson_title'] ?? ($p['course_title'] ?? 'Kurs'));
          ?>
          <tr class="st-pay-row"
              data-name="<?= htmlspecialchars(mb_strtolower($name,'UTF-8'), ENT_QUOTES) ?>"
              data-status="<?= htmlspecialchars($st ?: '—', ENT_QUOTES) ?>"
              data-amount="<?= htmlspecialchars(number_format($amt, 2, '.', ''), ENT_QUOTES) ?>"
              data-ts="<?= (int)$ts ?>">
            <td><?= h($name) ?></td>
            <td><span class="badge <?= $cls ?>"><?= h($st ?: '—') ?></span></td>
            <td><?= number_format($amt, 2) ?> EUR</td>
            <td><?= $ts ? date('d M Y', $ts) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="4" class="text-center py-4 text-muted"><i class="bi bi-credit-card me-1"></i>Nuk ka regjistrime pagesash</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const rowsWrap = document.getElementById('stPayRows');
  const rows = rowsWrap ? Array.from(rowsWrap.querySelectorAll('.st-pay-row')) : [];
  const q = document.getElementById('stPaySearch');
  const clear = document.getElementById('stClearPaySearch');
  const status = document.getElementById('stStatusFilter');
  const sort = document.getElementById('stPaySort');

  function applyFilter(){
    const s = (q?.value || '').toLowerCase().trim();
    const st = (status?.value || 'ALL');
    rows.forEach(r=>{
      const name = (r.getAttribute('data-name')||'');
      const rStatus = (r.getAttribute('data-status')||'');
      const okQ = !s || name.includes(s);
      const okS = st==='ALL' || rStatus===st;
      r.style.display = (okQ && okS) ? '' : 'none';
    });
    if (clear) clear.classList.toggle('d-none', !(q && q.value));
  }

  function applySort(){
    if (!rowsWrap) return;
    const mode = (sort?.value || 'date_desc');
    const sorted = rows.slice().sort((a,b)=>{
      const ta = parseInt(a.getAttribute('data-ts')||'0',10);
      const tb = parseInt(b.getAttribute('data-ts')||'0',10);
      const aa = parseFloat(a.getAttribute('data-amount')||'0');
      const ab = parseFloat(b.getAttribute('data-amount')||'0');
      const na = (a.getAttribute('data-name')||'');
      const nb = (b.getAttribute('data-name')||'');
      if (mode==='date_asc') return ta - tb;
      if (mode==='amount_desc') return ab - aa;
      if (mode==='amount_asc') return aa - ab;
      if (mode==='name_asc') return na.localeCompare(nb);
      return tb - ta;
    });
    sorted.forEach(el=> rowsWrap.appendChild(el));
  }

  q?.addEventListener('input', applyFilter);
  clear?.addEventListener('click', ()=>{ if(q){ q.value=''; applyFilter(); q.focus(); } });
  status?.addEventListener('change', applyFilter);
  sort?.addEventListener('change', ()=>{ applySort(); applyFilter(); });

  applySort();
  applyFilter();
})();
</script>
