<?php
// プロジェクトに紐づく拠点・住人のIDを返すAPIエンドポイント
require_once __DIR__ . '/../config.php';
requireAuth();

header('Content-Type: application/json');

$pdo = db();
$projects = $pdo->query("SELECT id FROM projects")->fetchAll(PDO::FETCH_COLUMN);
$data = [];

foreach ($projects as $pid) {
    $bases = $pdo->prepare("SELECT base_id FROM project_bases WHERE project_id=?");
    $bases->execute([$pid]);
    $residents = $pdo->prepare("SELECT resident_id FROM project_residents WHERE project_id=?");
    $residents->execute([$pid]);

    $data[$pid] = [
        'bases' => $bases->fetchAll(PDO::FETCH_COLUMN),
        'residents' => $residents->fetchAll(PDO::FETCH_COLUMN),
    ];
}

echo json_encode($data);
