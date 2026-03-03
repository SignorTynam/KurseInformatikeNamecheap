<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/lib/database.php';

$course_id = (int)($_GET['course_id'] ?? 0);
$appt_id   = (int)($_GET['appointment_id'] ?? 0);
if ($course_id<=0 || $appt_id<=0) { http_response_code(400); exit('Bad request'); }

/* RBAC minimal (opsionale: kufizo për studentë të regjistruar në kurs) */
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }

$stmt = $pdo->prepare("SELECT a.*, c.title AS course_title FROM appointments a JOIN courses c ON c.id=a.course_id WHERE a.id=? AND a.course_id=?");
$stmt->execute([$appt_id, $course_id]);
$appt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$appt) { http_response_code(404); exit('Not found'); }

$title = $appt['title'] ?: ('Leksion - '.$appt['course_title']);
$desc  = $appt['description'] ?? '';
$start = (new DateTime($appt['appointment_date']))->format('Ymd\THis');
$end   = (new DateTime($appt['appointment_date'].' +1 hour'))->format('Ymd\THis'); // default 1h
$uid   = 'appt-'.$appt['id'].'@kurseinformatike.com';
$loc   = $appt['link'] ?: ($appt['AulaVirtuale'] ?? '');

$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//kurseinformatike//EN\r\n";
$ics.= "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:".gmdate('Ymd\THis\Z')."\r\n";
$ics.= "DTSTART:$start\r\nDTEND:$end\r\nSUMMARY:".addslashes($title)."\r\n";
if ($loc)  $ics.= "LOCATION:".addslashes($loc)."\r\n";
if ($desc) $ics.= "DESCRIPTION:".addslashes($desc)."\r\n";
$ics.= "END:VEVENT\r\nEND:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="appointment_'.$appt['id'].'.ics"');
echo $ics;
