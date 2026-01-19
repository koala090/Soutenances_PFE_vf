<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'coordinateur') {
    header('Location: ../login.php');
    exit;
}

$user = get_user_info();

// Récupérer toutes les soutenances planifiées
$stmt = $pdo->prepare("
    SELECT s.*, 
           p.titre as projet_titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           CONCAT(enc.prenom, ' ', enc.nom) as encadrant_nom,
           sa.nom as salle_nom
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
    JOIN salles sa ON s.salle_id = sa.id
    WHERE p.filiere_id = ?
    ORDER BY s.date_soutenance, s.heure_debut
");
$stmt->execute([$user['filiere_id']]);
$soutenances = $stmt->fetchAll();

// Grouper par date
$planning_par_date = [];
foreach ($soutenances as $s) {
    $date = $s['date_soutenance'];
    if (!isset($planning_par_date[$date])) {
        $planning_par_date[$date] = [];
    }
    $planning_par_date[$date][] = $s;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planning des Soutenances</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        .soutenance-card {
            border-left: 4px solid #0891b2;
            transition: all 0.2s;
        }
        .soutenance-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateX(4px);
        }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-calendar"></i> Planning des Soutenances</h1>
        <button onclick="window.print()" class="btn btn-outline-primary">
            <i class="bi bi-printer"></i> Imprimer
        </button>
    </div>

    <?php if (count($planning_par_date) > 0): ?>
        <?php foreach ($planning_par_date as $date => $soutenances_jour): ?>
        <div class="mb-5">
            <h3 class="mb-3 text-primary">
                <i class="bi bi-calendar-event"></i> 
                <?= date('l d F Y', strtotime($date)) ?>
            </h3>
            
            <div class="row">
                <?php foreach ($soutenances_jour as $s): ?>
                <div class="col-md-6 mb-3">
                    <div class="card soutenance-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?= htmlspecialchars($s['etudiant_nom']) ?></h5>
                                <span class="badge bg-info">
                                    <?= date('H:i', strtotime($s['heure_debut'])) ?> - 
                                    <?= date('H:i', strtotime($s['heure_fin'])) ?>
                                </span>
                            </div>
                            <p class="card-text text-muted small mb-2">
                                <?= htmlspecialchars(substr($s['projet_titre'], 0, 80)) ?>...
                            </p>
                            <div class="d-flex align-items-center justify-content-between text-sm">
                                <div>
                                    <i class="bi bi-person"></i> <?= htmlspecialchars($s['encadrant_nom']) ?>
                                </div>
                                <div>
                                    <i class="bi bi-door-closed"></i> <?= htmlspecialchars($s['salle_nom']) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> Aucune soutenance planifiée pour le moment.
        </div>
    <?php endif; ?>

    <a href="../dashboards/coordinateur.php" class="btn btn-secondary mt-3">
        <i class="bi bi-arrow-left"></i> Retour au tableau de bord
    </a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
