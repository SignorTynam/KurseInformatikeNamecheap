<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../lib/database.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

$u = require_role(['Administrator','Instruktor']);
$CSRF = csrf_token();

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$isAdmin = is_admin($u);
$meId = (int)$u['id'];

$courses = [];
$tests = [];
try {
  if ($isAdmin) {
    $courses = $pdo->query('SELECT id, title FROM courses ORDER BY title ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } else {
    $stC = $pdo->prepare('SELECT id, title FROM courses WHERE id_creator=? ORDER BY title ASC');
    $stC->execute([$meId]);
    $courses = $stC->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (PDOException $e) {
  $courses = [];
}

try {
  if ($isAdmin) {
    $tests = $pdo->query(
      "SELECT t.*, c.title AS course_title
       FROM tests t
       JOIN courses c ON c.id=t.course_id
       ORDER BY t.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } else {
    $stT = $pdo->prepare(
      "SELECT t.*, c.title AS course_title
       FROM tests t
       JOIN courses c ON c.id=t.course_id
       WHERE c.id_creator=?
       ORDER BY t.created_at DESC"
    );
    $stT->execute([$meId]);
    $tests = $stT->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (PDOException $e) {
  $tests = [];
}

?>
<!DOCTYPE html>
<html lang="sq">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Testet — kurseinformatike.com</title>

  <!-- Vendor -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">

  <!-- Base theme (km-*) -->
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <!-- Tests-specific -->
  <link rel="stylesheet" href="../css/km-tests-forms.css">
</head>
<body class="km-body">
<?php
  if ($isAdmin) {
    include __DIR__ . '/../navbar_logged_administrator.php';
  } else {
    include __DIR__ . '/../navbar_logged_instruktor.php';
  }
?>

<div class="container km-page-shell">

  <!-- Header (km-*) -->
  <div class="km-page-header">
    <div class="d-flex align-items-start align-items-lg-center justify-content-between gap-3 flex-wrap">
      <div class="flex-grow-1">
        <div class="km-breadcrumb small">
          <span class="km-breadcrumb-current">Testet</span>
        </div>
        <h1 class="km-page-title">
          <i class="fa-regular fa-clipboard me-2 text-primary"></i>
          Testet
        </h1>
        <div class="km-page-subtitle">Krijo, redakto ose shiko rezultatet e testeve.</div>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="km-pill-meta">
          <i class="fa-solid fa-list"></i>
          Total: <strong><?= (int)count($tests) ?></strong>
        </span>
        <span class="km-pill-meta">
          <i class="fa-solid fa-book"></i>
          Kurse: <strong><?= (int)count($courses) ?></strong>
        </span>
      </div>
    </div>
  </div>

  <div class="row g-3 km-form-grid mt-2">

    <!-- MAIN -->
    <div class="col-12 col-lg-8">

      <!-- Create test card -->
      <div class="km-card km-card-main">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge">1</span> Krijo test të ri</h2>
            <div class="km-card-subtitle">Cilësimet bazë dhe informacioni</div>
          </div>
        </div>
        <div class="km-card-body">
          <form id="createTestForm" class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Titulli i testit *</label>
              <input type="text" name="title" class="form-control" placeholder="p.sh. Quiz kapitulli 3" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Kursi *</label>
              <select name="course_id" class="form-select" required>
                <option value="">Zgjidh kursin...</option>
                <?php foreach ($courses as $c): ?>
                  <option value="<?= (int)$c['id'] ?>"><?= h($c['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Përshkrimi</label>
              <textarea name="description" class="form-control" rows="3" placeholder="Shkruaj përshkrimin (opsional)"></textarea>
            </div>

            <div class="col-md-4">
              <label class="form-label">Kohëzgjatja (min)</label>
              <input type="number" name="time_limit_minutes" class="form-control" min="0" value="30">
            </div>
            <div class="col-md-4">
              <label class="form-label">Pass Score (%)</label>
              <input type="number" name="pass_score" class="form-control" min="0" max="100" value="60">
            </div>
            <div class="col-md-4">
              <label class="form-label">Max Attempts</label>
              <input type="number" name="max_attempts" class="form-control" min="0" value="1">
            </div>

            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="shuffle_questions" id="sq">
                <label class="form-check-label" for="sq">Përziej pyetjet/përgjigjet</label>
              </div>
              <div class="km-help-text mt-1">Opsional: përziej renditjen gjatë testimit.</div>
            </div>

            <div class="col-12">
              <div class="km-tests-actionbar">
                <div class="km-tests-sumrow">
                  <span class="km-badge km-badge-muted"><i class="fa-solid fa-shield-halved"></i> CSRF OK</span>
                  <span class="km-help-text">Pas krijimit, testi shfaqet te lista.</span>
                </div>
                <div class="km-tests-actionbar-right">
                  <button class="btn btn-primary km-btn-pill" type="submit">
                    <i class="fa-solid fa-circle-plus me-1"></i> Krijo
                  </button>
                </div>
              </div>
            </div>
          </form>

          <div id="createMsg" class="mt-3"></div>
        </div>
      </div>

      <!-- List card -->
      <div class="km-card mt-3">
        <div class="km-card-header">
          <div>
            <h2 class="km-card-title"><span class="km-step-badge">2</span> Testet ekzistuese</h2>
            <div class="km-card-subtitle">Redakto, shiko pyetjet ose rezultatet</div>
          </div>
        </div>
        <div class="km-card-body">
          <div class="table-responsive">
            <table class="table km-table mb-0">
              <thead>
                <tr>
                  <th style="width:70px">#</th>
                  <th>Titulli</th>
                  <th>Kursi</th>
                  <th style="width:140px">Status</th>
                  <th style="width:220px">Veprime</th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($tests)): ?>
                <tr>
                  <td colspan="5" class="text-center py-5">
                    <div class="km-help-text">S'ka teste të krijuara ende.</div>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($tests as $t): ?>
                  <tr>
                    <td><?= (int)$t['id'] ?></td>
                    <td><strong><?= h($t['title']) ?></strong></td>
                    <td><?= h($t['course_title']) ?></td>
                    <td>
                      <span class="km-badge km-badge-secondary">
                        <i class="fa-solid fa-circle-info"></i>
                        <?= h($t['status']) ?>
                      </span>
                    </td>
                    <td class="d-flex gap-2 flex-wrap">
                      <a class="btn btn-sm btn-outline-secondary km-btn-pill" href="test_edit.php?test_id=<?= (int)$t['id'] ?>">Edit</a>
                      <a class="btn btn-sm btn-outline-secondary km-btn-pill" href="test_builder.php?test_id=<?= (int)$t['id'] ?>&step=2">Builder</a>
                      <a class="btn btn-sm btn-outline-secondary km-btn-pill" href="test_results.php?test_id=<?= (int)$t['id'] ?>">Rezultate</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- SIDE -->
    <div class="col-12 col-lg-4">
      <div class="km-card km-card-side km-sticky-side">
        <div class="km-card-header">
          <div>
            <div class="km-side-title"><i class="fa-solid fa-chart-simple me-2"></i> Përmbledhje</div>
            <div class="km-card-subtitle">Statistika & tips</div>
          </div>
        </div>
        <div class="km-card-body">
          <div class="km-tests-preview-box">
            <div class="km-tests-preview-row"><span>Total teste</span><strong><?= (int)count($tests) ?></strong></div>
            <div class="km-tests-preview-row"><span>Kurse të aksesueshme</span><strong><?= (int)count($courses) ?></strong></div>
            <div class="km-tests-preview-row"><span>Roli</span><strong><?= h($isAdmin ? 'Administrator' : 'Instruktor') ?></strong></div>
          </div>

          <div class="km-tests-divider my-3"></div>
          <div class="km-help-text"><strong>Tips</strong></div>
          <ul class="km-checklist mt-2">
            <li><i class="fa-solid fa-check"></i> Krijo test të ri me formën majtas</li>
            <li><i class="fa-solid fa-check"></i> Shto/ndrysho pyetjet te Edit</li>
            <li><i class="fa-solid fa-check"></i> Shiko rezultatet te Rezultate</li>
          </ul>
        </div>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../footer2.php'; ?>


  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const CSRF = <?= json_encode($CSRF) ?>;
    const form = document.getElementById('createTestForm');
    const msg = document.getElementById('createMsg');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const formData = new FormData(form);
      const payload = Object.fromEntries(formData.entries());
      payload.shuffle_questions = formData.get('shuffle_questions') ? 1 : 0;
      payload.shuffle_choices = formData.get('shuffle_choices') ? 1 : 0;
      const res = await fetch('../api/tests_create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (data.ok) {
        msg.innerHTML = '<div class="alert alert-success">Testi u krijua.</div>';
        setTimeout(() => location.reload(), 600);
      } else {
        msg.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
      }
    });
  </script>
</body>
</html>
