<?php
declare(strict_types=1);

function notify_admins(PDO $pdo, string $type, string $title, ?string $body=null, ?string $targetUrl=null, ?int $actorUserId=null, ?int $courseId=null): void {
  $pdo->beginTransaction();
  try {
    $ins = $pdo->prepare("INSERT INTO notifications (type, title, body, target_url, actor_user_id, course_id) VALUES (?,?,?,?,?,?)");
    $ins->execute([$type, $title, $body, $targetUrl, $actorUserId, $courseId]);
    $notifId = (int)$pdo->lastInsertId();

    // fan-out te gjithë administratorët
    $admins = $pdo->query("SELECT id FROM users WHERE role='Administrator'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($admins) {
      $nu = $pdo->prepare("INSERT IGNORE INTO notification_users (notification_id, user_id) VALUES (?,?)");
      foreach ($admins as $aid) { $nu->execute([$notifId, (int)$aid]); }
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('notify_admins error: '.$e->getMessage());
  }
}
