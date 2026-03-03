<?php
session_start();
require_once 'database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lexo dhe dekodo të dhënat JSON të dërguara nga kërkesa
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    $file_id = $data['file_id'] ?? null;

    if ($file_id) {
        $stmt = $pdo->prepare("SELECT file_path FROM assignments_files WHERE id = ?");
        $stmt->execute([$file_id]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($file) {
            $filePath = $file['file_path'];
            // Ndërto rrugën absolute duke përdorur __DIR__
            $absolutePath = __DIR__ . '/' . $filePath;
            if (file_exists($absolutePath)) {
                if (unlink($absolutePath)) {
                    $stmtDelete = $pdo->prepare("DELETE FROM assignments_files WHERE id = ?");
                    $stmtDelete->execute([$file_id]);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Gabim gjatë fshirjes së skedarit. Nuk mund të fshihet skedari.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Gabim gjatë fshirjes së skedarit. Skedari nuk ekziston.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Skedari nuk u gjet në databazë.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'ID e skedarit nuk është specifikuar.']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Kërkesa nuk është e vlefshme.']);
}
?>
