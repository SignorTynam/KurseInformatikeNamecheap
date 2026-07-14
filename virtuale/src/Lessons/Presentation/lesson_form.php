<?php
/** @var array $course @var array $sections @var array $values @var array $initialBlocks @var array $errors */
$escape = static fn (?string $value): string => htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$isEdit = isset($lessonId) && $lessonId > 0;
foreach ($initialBlocks as &$initialBlock) {
    if (($initialBlock['type'] ?? '') === 'image' && (int)($initialBlock['data']['mediaId'] ?? 0) > 0) {
        $initialBlock['data']['url'] = '../lesson_media.php?id=' . (int)$initialBlock['data']['mediaId'];
    }
}
unset($initialBlock);
?>
<!doctype html>
<html lang="sq">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $isEdit ? 'Ndrysho leksionin' : 'Shto leksion' ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="../css/km-lms-forms.css">
  <link rel="stylesheet" href="../css/lesson-editor.css?ver=1">
</head>
<body class="bg-light">
<main class="container py-4 py-lg-5">
  <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
    <div><p class="text-muted mb-1"><?= $escape((string) $course['title']) ?></p><h1 class="h3 mb-0"><?= $isEdit ? 'Ndrysho leksionin' : 'Shto leksion të ri' ?></h1></div>
    <a class="btn btn-outline-secondary" href="../course_details.php?course_id=<?= (int) $course['id'] ?>&tab=materials">Kthehu</a>
  </div>

  <?php if ($errors !== []): ?>
    <div class="alert alert-danger" role="alert"><strong>Kontrolloni të dhënat:</strong><ul class="mb-0 mt-2"><?php foreach ($errors as $error): ?><li><?= $escape((string) $error) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="course_id" value="<?= (int) $course['id'] ?>">
    <?php if ($isEdit): ?><input type="hidden" name="lesson_id" value="<?= (int) $lessonId ?>"><?php endif; ?>
    <input type="hidden" name="draft_token" value="<?= $escape($draftToken) ?>">
    <input type="hidden" name="blocks_json" id="blocks_json">
    <?php if (!empty($copyLessonId)): ?><input type="hidden" name="copy_lesson_id" value="<?= (int) $copyLessonId ?>"><?php endif; ?>

    <section class="card shadow-sm border-0 mb-4"><div class="card-body p-3 p-lg-4"><div class="row g-3">
      <div class="col-12 col-lg-8"><label class="form-label" for="title">Titulli</label><input class="form-control" id="title" name="title" maxlength="255" required value="<?= $escape((string) ($values['title'] ?? '')) ?>"></div>
      <div class="col-12 col-lg-4"><label class="form-label" for="category">Kategoria</label><select class="form-select" id="category" name="category" required><?php foreach (['LEKSION','VIDEO','LINK','FILE','REFERENCA','LAB','TJETER'] as $category): ?><option value="<?= $category ?>" <?= ($values['category'] ?? 'LEKSION') === $category ? 'selected' : '' ?>><?= $category ?></option><?php endforeach; ?></select></div>
      <div class="col-12 col-md-6"><label class="form-label" for="section_id">Seksioni</label><select class="form-select" id="section_id" name="section_id"><option value="0">Pa seksion</option><?php foreach ($sections as $section): ?><option value="<?= (int) $section['id'] ?>" <?= (int) ($values['section_id'] ?? 0) === (int) $section['id'] ? 'selected' : '' ?>><?= $escape((string) $section['title']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12 col-md-6"><label class="form-label" for="url">URL HTTP/HTTPS</label><input class="form-control" type="url" id="url" name="url" value="<?= $escape((string) ($values['url'] ?? '')) ?>"></div>
      <div class="col-12 col-md-6"><label class="form-label" for="video_urls">Video (një URL për rresht)</label><textarea class="form-control" id="video_urls" name="video_urls" rows="3"><?= $escape((string) ($values['video_urls_text'] ?? '')) ?></textarea></div>
      <div class="col-12 col-md-6"><label class="form-label" for="notebook_path">Notebook</label><input class="form-control" id="notebook_path" name="notebook_path" value="<?= $escape((string) ($values['notebook_path'] ?? '')) ?>"><label class="form-label mt-3" for="lesson_file">Shto skedar</label><input class="form-control" type="file" id="lesson_file" name="lesson_file"></div>
    </div></div></section>

    <section class="card shadow-sm border-0"><div class="card-body p-3 p-lg-4">
      <div class="d-flex justify-content-between align-items-center gap-2 mb-2"><h2 class="h5 mb-0">Përmbajtja</h2><span id="lesson-unsaved" class="km-unsaved" hidden></span></div>
      <div class="km-editor-toolbar" role="toolbar" aria-label="Shto përmbajtje">
        <button type="button" data-insert-block="paragraph">Shto tekst</button><button type="button" data-insert-block="heading">Shto titull</button><button type="button" data-insert-block="list">Shto listë</button><button type="button" data-insert-block="table">Shto tabelë</button><button type="button" data-insert-block="image">Shto foto</button><button type="button" data-insert-block="quote">Shto citim</button><button type="button" data-insert-block="callout">Shto njoftim</button><button type="button" data-insert-block="code">Shto kod</button><button type="button" data-insert-block="delimiter">Shto ndarës</button>
      </div>
      <div id="lesson-editor-error" class="km-editor-error" role="alert"></div>
      <div class="km-editor-shell" data-lesson-editor data-csrf-token="<?= $escape($csrfToken) ?>" data-course-id="<?= (int) $course['id'] ?>" data-draft-token="<?= $escape($draftToken) ?>" data-upload-url="../api/lessons/media/upload.php" data-preview-url="../api/lessons/preview.php"></div>
      <script type="application/json" id="lesson-editor-initial"><?= json_encode($initialBlocks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
      <div class="d-flex flex-wrap gap-2 justify-content-end mt-4"><button type="button" id="lesson-preview-button" class="btn btn-outline-primary">Shiko paraprakisht</button><button type="submit" class="btn btn-primary px-4">Ruaj</button></div>
    </div></section>
  </form>
</main>
<dialog id="lesson-preview-dialog" class="km-preview-dialog"><div class="km-preview-header"><strong>Pamja paraprake</strong><button type="button" id="lesson-preview-close" class="btn-close" aria-label="Mbyll"></button></div><article id="lesson-preview-content" class="km-preview-body lesson-content"></article></dialog>
<script src="../assets/dist/lesson-editor.js?ver=1" defer></script>
</body></html>
