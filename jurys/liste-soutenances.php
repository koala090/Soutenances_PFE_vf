<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['coordinateur', 'directeur']);

$user = get_user_info();

// Filtres
$filtre_statut = $_GET['statut'] ?? 'tous';
$filtre_date = $_GET['date'] ?? 'tous';

$where_clauses = [];
$params = [];

if ($user['role'] === 'coordinateur') {
    $where_clauses[] = "p.filiere_id = ?";
    $params[] = $user['filiere_id'];
}

if ($filtre_statut !== 'tous') {
    $where_clauses[] = "s.statut = ?";
    $params[] = $filtre_statut;
}

if ($filtre_date === 'avenir') {
    $where_clauses[] = "s.date_soutenance >= CURDATE()";
} elseif ($filtre_date === 'passe') {
    $where_clauses[] = "s.date_soutenance < CURDATE()";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Récupérer toutes les soutenances
$stmt = $pdo->prepare("
    SELECT s.*, p.titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           CONCAT(b.prenom, ' ', b.nom) as binome_nom,
           f.nom as filiere_nom,
           sa.nom as salle_nom,
           COUNT(j.id) as nb_jury,
           SUM(CASE WHEN j.convocation_envoyee = 1 THEN 1 ELSE 0 END) as nb_convocs
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs b ON p.binome_id = b.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    LEFT JOIN jurys j ON s.id = j.soutenance_id
    $where_sql
    GROUP BY s.id
    ORDER BY s.date_soutenance DESC, s.heure_debut DESC
");
$stmt->execute($params);
$soutenances = $stmt->fetchAll();

// Statistiques
$total = count($soutenances);
$planifiees = count(array_filter($soutenances, fn($s) => $s['statut'] === 'planifiee'));
$terminees = count(array_filter($soutenances, fn($s) => $s['statut'] === 'terminee'));
$jurys_complets = count(array_filter($soutenances, fn($s) => $s['nb_jury'] >= 3));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vue d'ensemble - Soutenances et Jurys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-list-check"></i> Vue d'ensemble des Soutenances</h1>
            <a href="../dashboards/<?= $user['role'] ?>.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>

        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="text-muted">Total</h6>
                        <h2 class="text-primary"><?= $total ?></h2>
                        <small>soutenances</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="text-muted">Planifiées</h6>
                        <h2 class="text-info"><?= $planifiees ?></h2>
                        <small>en attente</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="text-muted">Terminées</h6>
                        <h2 class="text-success"><?= $terminees ?></h2>
                        <small>complètes</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h6 class="text-muted">Jurys complets</h6>
                        <h2 class="text-<?= $jurys_complets === $total ? 'success' : 'warning' ?>">
                            <?= $jurys_complets ?>/<?= $total ?>
                        </h2>
                        <small>≥ 3 membres</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Statut</label>
                        <select name="statut" class="form-select" onchange="this.form.submit()">
                            <option value="tous" <?= $filtre_statut === 'tous' ? 'selected' : '' ?>>Tous</option>
                            <option value="planifiee" <?= $filtre_statut === 'planifiee' ? 'selected' : '' ?>>Planifiées</option>
                            <option value="confirmee" <?= $filtre_statut === 'confirmee' ? 'selected' : '' ?>>Confirmées</option>
                            <option value="en_cours" <?= $filtre_statut === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                            <option value="terminee" <?= $filtre_statut === 'terminee' ? 'selected' : '' ?>>Terminées</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Période</label>
                        <select name="date" class="form-select" onchange="this.form.submit()">
                            <option value="tous" <?= $filtre_date === 'tous' ? 'selected' : '' ?>>Toutes</option>
                            <option value="avenir" <?= $filtre_date === 'avenir' ? 'selected' : '' ?>>À venir</option>
                            <option value="passe" <?= $filtre_date === 'passe' ? 'selected' : '' ?>>Passées</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <a href="?" class="btn btn-secondary">
                            <i class="bi bi-x-circle"></i> Réinitialiser
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Liste des soutenances -->
        <?php if (count($soutenances) > 0): ?>
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-calendar-event"></i> 
                        Soutenances (<?= count($soutenances) ?>)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Heure</th>
                                    <th>Étudiant(s)</th>
                                    <th>Projet</th>
                                    <th>Salle</th>
                                    <th>Jury</th>
                                    <th>Convocs</th>
                                    <th>Note</th>
                                    <th>Statut</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($soutenances as $sout): ?>
                                    <tr>
                                        <td><?= formater_date($sout['date_soutenance'], 'd/m/Y') ?></td>
                                        <td>
                                            <small>
                                                <?= date('H:i', strtotime($sout['heure_debut'])) ?><br>
                                                <?= date('H:i', strtotime($sout['heure_fin'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($sout['etudiant_nom']) ?></strong>
                                            <?php if ($sout['binome_nom']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($sout['binome_nom']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?= htmlspecialchars(substr($sout['titre'], 0, 50)) ?><?= strlen($sout['titre']) > 50 ? '...' : '' ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($sout['salle_nom']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $sout['nb_jury'] >= 3 ? 'success' : 'warning' ?>">
                                                <?= $sout['nb_jury'] ?>/3
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($sout['nb_jury'] > 0): ?>
                                                <span class="badge bg-<?= $sout['nb_convocs'] >= $sout['nb_jury'] ? 'success' : 'secondary' ?>">
                                                    <?= $sout['nb_convocs'] ?>/<?= $sout['nb_jury'] ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($sout['note_finale']): ?>
                                                <span class="badge bg-success">
                                                    <?= number_format($sout['note_finale'], 2) ?>/20
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $statut_badges = [
                                                'planifiee' => ['bg' => 'info', 'text' => 'Planifiée'],
                                                'confirmee' => ['bg' => 'primary', 'text' => 'Confirmée'],
                                                'en_cours' => ['bg' => 'warning', 'text' => 'En cours'],
                                                'terminee' => ['bg' => 'success', 'text' => 'Terminée'],
                                                'reportee' => ['bg' => 'warning', 'text' => 'Reportée'],
                                                'annulee' => ['bg' => 'danger', 'text' => 'Annulée']
                                            ];
                                            $badge = $statut_badges[$sout['statut']] ?? ['bg' => 'secondary', 'text' => $sout['statut']];
                                            ?>
                                            <span class="badge bg-<?= $badge['bg'] ?>"><?= $badge['text'] ?></span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="constituer.php#sout-<?= $sout['id'] ?>" 
                                                   class="btn btn-outline-primary" 
                                                   title="Gérer le jury">
                                                    <i class="bi bi-people"></i>
                                                </a>
                                                <?php if ($sout['nb_jury'] > 0): ?>
                                                    <a href="../documents/convocation.php?soutenance_id=<?= $sout['id'] ?>&action=generer" 
                                                       class="btn btn-outline-info" 
                                                       title="Convocation">
                                                        <i class="bi bi-send"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($sout['statut'] === 'terminee'): ?>
                                                    <a href="../documents/pv.php?soutenance_id=<?= $sout['id'] ?>&action=generer" 
                                                       class="btn btn-outline-success" 
                                                       title="PV">
                                                        <i class="bi bi-file-earmark-pdf"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Aucune soutenance ne correspond aux critères sélectionnés.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>