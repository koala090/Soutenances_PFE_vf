<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['coordinateur']);

$user = get_user_info();
$message = '';
$type_message = '';

// Fonction pour calculer l'écart-type (mesure de déséquilibre)
function calculerEcartType($charges) {
    if (count($charges) === 0) return 0;
    $moyenne = array_sum($charges) / count($charges);
    $variance = 0;
    foreach ($charges as $c) {
        $variance += pow($c - $moyenne, 2);
    }
    return sqrt($variance / count($charges));
}

// Récupérer les statistiques actuelles
$stmt = $pdo->prepare("
    SELECT u.id, u.nom, u.prenom,
           COUNT(j.id) as nb_jurys,
           SUM(CASE WHEN j.role_jury = 'president' THEN 1 ELSE 0 END) as nb_presidences,
           SUM(CASE WHEN j.role_jury = 'encadrant' THEN 1 ELSE 0 END) as nb_encadrements,
           SUM(CASE WHEN j.role_jury = 'examinateur' THEN 1 ELSE 0 END) as nb_examens
    FROM utilisateurs u
    LEFT JOIN jurys j ON u.id = j.professeur_id
    LEFT JOIN soutenances s ON j.soutenance_id = s.id
    LEFT JOIN projets p ON s.projet_id = p.id
    WHERE u.role = 'professeur' AND u.filiere_id = ?
    AND (s.date_soutenance >= CURDATE() OR s.date_soutenance IS NULL)
    GROUP BY u.id
    ORDER BY nb_jurys DESC
");
$stmt->execute([$user['filiere_id']]);
$stats_profs = $stmt->fetchAll();

$charges = array_column($stats_profs, 'nb_jurys');
$ecart_type_actuel = calculerEcartType($charges);
$charge_min = min($charges) ?? 0;
$charge_max = max($charges) ?? 0;
$charge_moy = count($charges) > 0 ? array_sum($charges) / count($charges) : 0;

// Action : Proposition de rééquilibrage
$propositions = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'proposer') {
    // Identifier les professeurs surchargés et sous-chargés
    $surcharges = [];
    $sous_charges = [];
    
    foreach ($stats_profs as $prof) {
        if ($prof['nb_jurys'] > $charge_moy + 1) {
            $surcharges[] = $prof;
        } elseif ($prof['nb_jurys'] < $charge_moy - 1) {
            $sous_charges[] = $prof;
        }
    }
    
    // Pour chaque prof surchargé, proposer de déplacer des jurys
    foreach ($surcharges as $surcharge) {
        // Récupérer ses jurys (sauf encadrant)
        $stmt = $pdo->prepare("
            SELECT j.id, j.role_jury, s.id as soutenance_id, s.date_soutenance,
                   CONCAT(e.prenom, ' ', e.nom) as etudiant_nom
            FROM jurys j
            JOIN soutenances s ON j.soutenance_id = s.id
            JOIN projets p ON s.projet_id = p.id
            JOIN utilisateurs e ON p.etudiant_id = e.id
            WHERE j.professeur_id = ? AND j.role_jury != 'encadrant'
            AND s.date_soutenance >= CURDATE()
            ORDER BY s.date_soutenance
            LIMIT 2
        ");
        $stmt->execute([$surcharge['id']]);
        $jurys_a_deplacer = $stmt->fetchAll();
        
        foreach ($jurys_a_deplacer as $jury) {
            // Trouver un remplaçant parmi les sous-chargés
            if (count($sous_charges) > 0) {
                $remplacant = $sous_charges[0];
                
                $propositions[] = [
                    'jury_id' => $jury['id'],
                    'soutenance_id' => $jury['soutenance_id'],
                    'ancien_prof' => $surcharge['prenom'] . ' ' . $surcharge['nom'],
                    'ancien_prof_id' => $surcharge['id'],
                    'nouveau_prof' => $remplacant['prenom'] . ' ' . $remplacant['nom'],
                    'nouveau_prof_id' => $remplacant['id'],
                    'role' => $jury['role_jury'],
                    'date_soutenance' => $jury['date_soutenance'],
                    'etudiant' => $jury['etudiant_nom']
                ];
            }
        }
    }
    
    $message = count($propositions) . " proposition(s) de rééquilibrage générée(s)";
    $type_message = 'success';
}

// Action : Appliquer le rééquilibrage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'appliquer') {
    try {
        $pdo->beginTransaction();
        
        $nb_modifies = 0;
        foreach ($_POST['propositions'] ?? [] as $prop_json) {
            $prop = json_decode($prop_json, true);
            
            // Vérifier que le nouveau prof n'est pas déjà dans le jury
            $stmt = $pdo->prepare("
                SELECT id FROM jurys 
                WHERE soutenance_id = ? AND professeur_id = ?
            ");
            $stmt->execute([$prop['soutenance_id'], $prop['nouveau_prof_id']]);
            
            if (!$stmt->fetch()) {
                // Mettre à jour le jury
                $stmt = $pdo->prepare("
                    UPDATE jurys 
                    SET professeur_id = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$prop['nouveau_prof_id'], $prop['jury_id']]);
                $nb_modifies++;
            }
        }
        
        $pdo->commit();
        $message = "$nb_modifies jury(s) rééquilibré(s) avec succès";
        $type_message = 'success';
        
        // Recharger
        header("Location: equilibrer.php?success=1");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Erreur : " . $e->getMessage();
        $type_message = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Équilibrage des Jurys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-bar-chart"></i> Équilibrage des Jurys</h1>
            <a href="constituer.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $type_message === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> Rééquilibrage effectué avec succès
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <!-- Statistiques globales -->
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Charge Min</h6>
                        <h2 class="text-success"><?= $charge_min ?></h2>
                        <small>jurys</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Charge Max</h6>
                        <h2 class="text-danger"><?= $charge_max ?></h2>
                        <small>jurys</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Charge Moyenne</h6>
                        <h2 class="text-primary"><?= number_format($charge_moy, 1) ?></h2>
                        <small>jurys</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h6 class="text-muted">Écart-type</h6>
                        <h2 class="text-<?= $ecart_type_actuel > 2 ? 'danger' : ($ecart_type_actuel > 1 ? 'warning' : 'success') ?>">
                            <?= number_format($ecart_type_actuel, 2) ?>
                        </h2>
                        <small><?= $ecart_type_actuel > 2 ? 'Déséquilibré' : ($ecart_type_actuel > 1 ? 'Moyen' : 'Équilibré') ?></small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <!-- Tableau des charges -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-table"></i> Charges par Professeur</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Professeur</th>
                                        <th>Total Jurys</th>
                                        <th>Présidences</th>
                                        <th>Encadrements</th>
                                        <th>Examens</th>
                                        <th>État</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats_profs as $prof): ?>
                                        <?php
                                        $etat = 'normal';
                                        $badge_class = 'secondary';
                                        if ($prof['nb_jurys'] > $charge_moy + 1) {
                                            $etat = 'Surchargé';
                                            $badge_class = 'danger';
                                        } elseif ($prof['nb_jurys'] < $charge_moy - 1) {
                                            $etat = 'Sous-chargé';
                                            $badge_class = 'success';
                                        } else {
                                            $etat = 'Normal';
                                        }
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?></strong></td>
                                            <td><span class="badge bg-primary"><?= $prof['nb_jurys'] ?></span></td>
                                            <td><?= $prof['nb_presidences'] ?></td>
                                            <td><?= $prof['nb_encadrements'] ?></td>
                                            <td><?= $prof['nb_examens'] ?></td>
                                            <td><span class="badge bg-<?= $badge_class ?>"><?= $etat ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Propositions de rééquilibrage -->
                <?php if (count($propositions) > 0): ?>
                    <div class="card">
                        <div class="card-header bg-warning">
                            <h5 class="mb-0"><i class="bi bi-shuffle"></i> Propositions de Rééquilibrage</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="appliquer">
                                
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Sélection</th>
                                                <th>Soutenance</th>
                                                <th>Rôle</th>
                                                <th>Actuel</th>
                                                <th></th>
                                                <th>Proposé</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($propositions as $prop): ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" 
                                                               name="propositions[]" 
                                                               value="<?= htmlspecialchars(json_encode($prop)) ?>"
                                                               checked>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?= formater_date($prop['date_soutenance'], 'd/m/Y') ?><br>
                                                            <?= htmlspecialchars($prop['etudiant']) ?>
                                                        </small>
                                                    </td>
                                                    <td><span class="badge bg-secondary"><?= ucfirst($prop['role']) ?></span></td>
                                                    <td><?= htmlspecialchars($prop['ancien_prof']) ?></td>
                                                    <td><i class="bi bi-arrow-right"></i></td>
                                                    <td><strong><?= htmlspecialchars($prop['nouveau_prof']) ?></strong></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <button type="submit" class="btn btn-success" onclick="return confirm('Appliquer ces modifications ?')">
                                    <i class="bi bi-check-circle"></i> Appliquer le rééquilibrage
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Graphique -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-graph-up"></i> Répartition</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="chartCharges"></canvas>
                    </div>
                </div>

                <!-- Actions -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="bi bi-gear"></i> Actions</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="proposer">
                            <button type="submit" class="btn btn-primary w-100 mb-2">
                                <i class="bi bi-lightbulb"></i>
                                Proposer un rééquilibrage
                            </button>
                        </form>
                        
                        <div class="alert alert-info mb-0 mt-3">
                            <small>
                                <i class="bi bi-info-circle"></i>
                                L'algorithme identifie les déséquilibres et propose des transferts de jurys
                                pour optimiser la répartition.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Graphique de répartition
        const ctx = document.getElementById('chartCharges');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: [
                    <?php foreach ($stats_profs as $p): ?>
                        '<?= htmlspecialchars(substr($p['nom'], 0, 10)) ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Nombre de jurys',
                    data: [
                        <?php foreach ($stats_profs as $p): ?>
                            <?= $p['nb_jurys'] ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        <?php foreach ($stats_profs as $p): ?>
                            '<?= $p['nb_jurys'] > $charge_moy + 1 ? "rgba(220, 53, 69, 0.7)" : ($p['nb_jurys'] < $charge_moy - 1 ? "rgba(25, 135, 84, 0.7)" : "rgba(13, 110, 253, 0.7)") ?>',
                        <?php endforeach; ?>
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>