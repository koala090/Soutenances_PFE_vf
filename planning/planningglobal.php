<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'directeur') {
    header('Location: ../login.php');
    exit;
}

$user = get_user_info();

// Filtre par filière (optionnel)
$filiere_filter = isset($_GET['filiere']) ? $_GET['filiere'] : 'all';

// Récupérer toutes les filières
$stmt = $pdo->query("SELECT id, code, nom FROM filieres ORDER BY nom");
$filieres = $stmt->fetchAll();

// Récupérer les statistiques globales
$stats_query = "
    SELECT 
        COUNT(DISTINCT s.id) as total_soutenances,
        COUNT(DISTINCT CASE WHEN s.statut = 'planifiee' THEN s.id END) as planifiees,
        COUNT(DISTINCT CASE WHEN s.statut = 'terminee' THEN s.id END) as terminees,
        AVG(CASE WHEN s.statut = 'terminee' THEN s.note_finale END) as moyenne_generale
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
";
if ($filiere_filter !== 'all') {
    $stats_query .= " WHERE p.filiere_id = " . intval($filiere_filter);
}
$stats = $pdo->query($stats_query)->fetch();

// Récupérer toutes les soutenances planifiées
$query = "
    SELECT s.*, 
           p.titre as projet_titre,
           p.filiere_id,
           f.nom as filiere_nom,
           f.code as filiere_code,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           CONCAT(b.prenom, ' ', b.nom) as binome_nom,
           CONCAT(enc.prenom, ' ', enc.nom) as encadrant_nom,
           sa.nom as salle_nom,
           sa.batiment
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs b ON p.binome_id = b.id
    LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
    JOIN salles sa ON s.salle_id = sa.id
";

if ($filiere_filter !== 'all') {
    $query .= " WHERE p.filiere_id = ?";
    $stmt = $pdo->prepare($query . " ORDER BY s.date_soutenance, s.heure_debut");
    $stmt->execute([$filiere_filter]);
} else {
    $stmt = $pdo->query($query . " ORDER BY s.date_soutenance, s.heure_debut");
}
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

// Statistiques par filière
$stats_filieres_query = "
    SELECT 
        f.id,
        f.nom as filiere,
        f.code,
        COUNT(s.id) as nb_soutenances,
        AVG(CASE WHEN s.statut = 'terminee' THEN s.note_finale END) as moyenne,
        COUNT(CASE WHEN s.statut = 'terminee' THEN 1 END) as terminees
    FROM filieres f
    LEFT JOIN projets p ON f.id = p.filiere_id
    LEFT JOIN soutenances s ON p.id = s.projet_id
    GROUP BY f.id
    ORDER BY f.nom
";
$stats_filieres = $pdo->query($stats_filieres_query)->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planning Global - Direction</title>
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
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
        }
        .stats-card-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .stats-card-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .stats-card-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .filiere-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .status-badge {
            font-size: 0.7rem;
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body class="bg-light">
<div class="container mt-4">
    <!-- En-tête -->
    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <div>
            <h1><i class="bi bi-calendar3"></i> Planning Global des Soutenances</h1>
            <p class="text-muted mb-0">Vue d'ensemble - Direction</p>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-outline-primary me-2">
                <i class="bi bi-printer"></i> Imprimer
            </button>
            <a href="../dashboards/directeur.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>
    </div>

    <!-- Statistiques globales -->
    <div class="row mb-4 no-print">
        <div class="col-md-3 mb-3">
            <div class="card stats-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase opacity-75 mb-1">Total Soutenances</h6>
                            <h2 class="mb-0"><?= $stats['total_soutenances'] ?></h2>
                        </div>
                        <div class="fs-1 opacity-50">
                            <i class="bi bi-calendar-check"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase opacity-75 mb-1">Planifiées</h6>
                            <h2 class="mb-0"><?= $stats['planifiees'] ?></h2>
                        </div>
                        <div class="fs-1 opacity-50">
                            <i class="bi bi-clock-history"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase opacity-75 mb-1">Terminées</h6>
                            <h2 class="mb-0"><?= $stats['terminees'] ?></h2>
                            <small class="opacity-75">
                                <?php 
                                $taux = $stats['total_soutenances'] > 0 
                                    ? round(($stats['terminees'] / $stats['total_soutenances']) * 100) 
                                    : 0;
                                echo "($taux%)";
                                ?>
                            </small>
                        </div>
                        <div class="fs-1 opacity-50">
                            <i class="bi bi-check-circle"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card stats-card-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-uppercase opacity-75 mb-1">Moyenne</h6>
                            <h2 class="mb-0">
                                <?= $stats['moyenne_generale'] ? number_format($stats['moyenne_generale'], 2) : 'N/A' ?>
                            </h2>
                            <small class="opacity-75">/20</small>
                        </div>
                        <div class="fs-1 opacity-50">
                            <i class="bi bi-graph-up"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistiques par filière -->
    <div class="card mb-4 no-print">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0"><i class="bi bi-bar-chart"></i> Statistiques par Filière</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Filière</th>
                            <th>Code</th>
                            <th class="text-center">Soutenances</th>
                            <th class="text-center">Terminées</th>
                            <th class="text-center">Moyenne</th>
                            <th class="text-center">Taux</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats_filieres as $sf): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($sf['filiere']) ?></strong></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($sf['code']) ?></span></td>
                            <td class="text-center"><?= $sf['nb_soutenances'] ?></td>
                            <td class="text-center"><?= $sf['terminees'] ?></td>
                            <td class="text-center">
                                <?php if ($sf['moyenne']): ?>
                                    <strong class="text-primary"><?= number_format($sf['moyenne'], 2) ?>/20</strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php 
                                $taux_fil = $sf['nb_soutenances'] > 0 
                                    ? round(($sf['terminees'] / $sf['nb_soutenances']) * 100) 
                                    : 0;
                                $badge_class = $taux_fil >= 80 ? 'success' : ($taux_fil >= 50 ? 'warning' : 'danger');
                                ?>
                                <span class="badge bg-<?= $badge_class ?>"><?= $taux_fil ?>%</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Filtres -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label"><i class="bi bi-funnel"></i> Filtrer par filière</label>
                    <select name="filiere" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?= $filiere_filter === 'all' ? 'selected' : '' ?>>
                            Toutes les filières
                        </option>
                        <?php foreach ($filieres as $filiere): ?>
                        <option value="<?= $filiere['id'] ?>" <?= $filiere_filter == $filiere['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($filiere['nom']) ?> (<?= htmlspecialchars($filiere['code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Planning des soutenances -->
    <div class="mb-4">
        <h2 class="mb-3">
            <i class="bi bi-calendar-week"></i> Planning des Soutenances
            <?php if ($filiere_filter !== 'all'): ?>
                <?php 
                $filiere_selectionnee = array_filter($filieres, fn($f) => $f['id'] == $filiere_filter);
                $filiere_selectionnee = reset($filiere_selectionnee);
                ?>
                <small class="text-muted">- <?= htmlspecialchars($filiere_selectionnee['nom']) ?></small>
            <?php endif; ?>
        </h2>

        <?php if (count($planning_par_date) > 0): ?>
             <?php foreach ($planning_par_date as $date => $soutenances_jour): ?>
               <div class="mb-5">
                    <div class="d-flex align-items-center mb-3">
                    <div class="bg-primary text-white px-4 py-2 rounded-3">
                     <h4 class="mb-0">
                     <i class="bi bi-calendar-event"></i> 
                     <?php
                     $date_obj = new DateTime($date);
                // Jours de la semaine en français
                     $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                // Mois en français
                     $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 
                            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
                
                     $jour_semaine = $jours[$date_obj->format('w')];
                     $jour = $date_obj->format('d');
                     $mois_texte = $mois[(int)$date_obj->format('m')];
                     $annee = $date_obj->format('Y');
                
                       echo "$jour_semaine $jour $mois_texte $annee";
                     ?>
                     </h4>
                    </div>
        <div class="ms-3">
            <span class="badge bg-info"><?= count($soutenances_jour) ?> soutenance(s)</span>
        </div>
    </div>
                
                <div class="row">
                    <?php foreach ($soutenances_jour as $s): ?>
                    <div class="col-lg-6 mb-3">
                        <div class="card soutenance-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="flex-grow-1">
                                        <h5 class="card-title mb-1">
                                            <i class="bi bi-person-circle text-primary"></i>
                                            <?= htmlspecialchars($s['etudiant_nom']) ?>
                                            <?php if ($s['binome_nom']): ?>
                                                <small class="text-muted">+ <?= htmlspecialchars($s['binome_nom']) ?></small>
                                            <?php endif; ?>
                                        </h5>
                                        <span class="badge filiere-badge bg-secondary mb-2">
                                            <?= htmlspecialchars($s['filiere_code']) ?> - <?= htmlspecialchars($s['filiere_nom']) ?>
                                        </span>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-info">
                                            <?= date('H:i', strtotime($s['heure_debut'])) ?> - 
                                            <?= date('H:i', strtotime($s['heure_fin'])) ?>
                                        </span>
                                        <br>
                                        <?php
                                        $status_classes = [
                                            'planifiee' => 'bg-primary',
                                            'confirmee' => 'bg-warning',
                                            'en_cours' => 'bg-danger',
                                            'terminee' => 'bg-success',
                                            'reportee' => 'bg-secondary',
                                            'annulee' => 'bg-dark'
                                        ];
                                        $status_labels = [
                                            'planifiee' => 'Planifiée',
                                            'confirmee' => 'Confirmée',
                                            'en_cours' => 'En cours',
                                            'terminee' => 'Terminée',
                                            'reportee' => 'Reportée',
                                            'annulee' => 'Annulée'
                                        ];
                                        $status_class = $status_classes[$s['statut']] ?? 'bg-secondary';
                                        $status_label = $status_labels[$s['statut']] ?? $s['statut'];
                                        ?>
                                        <span class="badge status-badge <?= $status_class ?> mt-1">
                                            <?= $status_label ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <p class="card-text text-muted small mb-3">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <strong>Projet:</strong> <?= htmlspecialchars(substr($s['projet_titre'], 0, 100)) ?>
                                    <?= strlen($s['projet_titre']) > 100 ? '...' : '' ?>
                                </p>
                                
                                <div class="row g-2 small">
                                    <div class="col-6">
                                        <i class="bi bi-person-badge text-success"></i>
                                        <strong>Encadrant:</strong><br>
                                        <?= htmlspecialchars($s['encadrant_nom']) ?>
                                    </div>
                                    <div class="col-6">
                                        <i class="bi bi-door-closed text-info"></i>
                                        <strong>Salle:</strong><br>
                                        <?= htmlspecialchars($s['salle_nom']) ?>
                                        <?php if ($s['batiment']): ?>
                                            <small class="text-muted">(<?= htmlspecialchars($s['batiment']) ?>)</small>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($s['note_finale']): ?>
                                    <div class="col-12 mt-2 pt-2 border-top">
                                        <i class="bi bi-star-fill text-warning"></i>
                                        <strong>Note:</strong>
                                        <span class="badge bg-success"><?= number_format($s['note_finale'], 2) ?>/20</span>
                                        <?php if ($s['mention']): ?>
                                            <span class="badge bg-primary"><?= ucfirst($s['mention']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
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
                <i class="bi bi-exclamation-triangle"></i> 
                Aucune soutenance planifiée pour le moment<?= $filiere_filter !== 'all' ? ' dans cette filière' : '' ?>.
            </div>
        <?php endif; ?>
    </div>

    <div class="no-print">
        <a href="../dashboards/directeur.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> Retour au tableau de bord
        </a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>