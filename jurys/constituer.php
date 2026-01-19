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

// Récupérer les soutenances planifiées sans jury complet (moins de 3 membres)
$stmt = $pdo->prepare("
    SELECT s.*, p.titre, CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           p.encadrant_id, CONCAT(enc.prenom, ' ', enc.nom) as encadrant_nom,
           sa.nom as salle_nom, f.nom as filiere_nom,
           COUNT(j.id) as nb_membres
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
    JOIN salles sa ON s.salle_id = sa.id
    JOIN filieres f ON p.filiere_id = f.id
    LEFT JOIN jurys j ON s.id = j.soutenance_id
    WHERE p.filiere_id = ? AND s.statut IN ('planifiee', 'confirmee')
    GROUP BY s.id
    HAVING nb_membres < 3
    ORDER BY s.date_soutenance, s.heure_debut
");
$stmt->execute([$user['filiere_id']]);
$soutenances = $stmt->fetchAll();

// Si on soumet le formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $soutenance_id = intval($_POST['soutenance_id']);
    
    // Récupérer les infos de la soutenance
    $stmt = $pdo->prepare("
        SELECT p.encadrant_id, p.filiere_id 
        FROM soutenances s 
        JOIN projets p ON s.projet_id = p.id 
        WHERE s.id = ?
    ");
    $stmt->execute([$soutenance_id]);
    $sout = $stmt->fetch();
    
    if (!$sout) {
        $message = "Soutenance introuvable";
        $type_message = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Vérifier si l'encadrant n'est pas déjà dans le jury
            $stmt = $pdo->prepare("SELECT id FROM jurys WHERE soutenance_id = ? AND professeur_id = ?");
            $stmt->execute([$soutenance_id, $sout['encadrant_id']]);
            
            if (!$stmt->fetch()) {
                // Ajouter l'encadrant automatiquement
                $stmt = $pdo->prepare("
                    INSERT INTO jurys (soutenance_id, professeur_id, role_jury) 
                    VALUES (?, ?, 'encadrant')
                ");
                $stmt->execute([$soutenance_id, $sout['encadrant_id']]);
            }
            
            // Ajouter le président (ne peut pas être l'encadrant)
            if (!empty($_POST['president_id'])) {
                $president_id = intval($_POST['president_id']);
                
                if ($president_id == $sout['encadrant_id']) {
                    throw new Exception("Le président ne peut pas être l'encadrant");
                }
                
                // Vérifier si pas déjà dans le jury
                $stmt = $pdo->prepare("SELECT id FROM jurys WHERE soutenance_id = ? AND professeur_id = ?");
                $stmt->execute([$soutenance_id, $president_id]);
                
                if ($stmt->fetch()) {
                    throw new Exception("Ce professeur est déjà membre du jury");
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO jurys (soutenance_id, professeur_id, role_jury) 
                    VALUES (?, ?, 'president')
                ");
                $stmt->execute([$soutenance_id, $president_id]);
            }
            
            // Ajouter l'examinateur
            if (!empty($_POST['examinateur_id'])) {
                $examinateur_id = intval($_POST['examinateur_id']);
                
                // Vérifier si pas déjà dans le jury
                $stmt = $pdo->prepare("SELECT id FROM jurys WHERE soutenance_id = ? AND professeur_id = ?");
                $stmt->execute([$soutenance_id, $examinateur_id]);
                
                if ($stmt->fetch()) {
                    throw new Exception("Ce professeur est déjà membre du jury");
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO jurys (soutenance_id, professeur_id, role_jury) 
                    VALUES (?, ?, 'examinateur')
                ");
                $stmt->execute([$soutenance_id, $examinateur_id]);
            }
            
            $pdo->commit();
            $message = "Jury constitué avec succès";
            $type_message = 'success';
            
            // Recharger les soutenances
            header("Location: constituer.php?success=1");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Erreur : " . $e->getMessage();
            $type_message = 'error';
        }
    }
}

// Récupérer tous les professeurs de la filière
$stmt = $pdo->prepare("
    SELECT id, nom, prenom, specialites 
    FROM utilisateurs 
    WHERE role = 'professeur' AND filiere_id = ? 
    ORDER BY nom, prenom
");
$stmt->execute([$user['filiere_id']]);
$professeurs = $stmt->fetchAll();

// Statistiques des jurys par professeur
$stmt = $pdo->prepare("
    SELECT u.id, u.nom, u.prenom, COUNT(j.id) as nb_jurys
    FROM utilisateurs u
    LEFT JOIN jurys j ON u.id = j.professeur_id
    LEFT JOIN soutenances s ON j.soutenance_id = s.id
    LEFT JOIN projets p ON s.projet_id = p.id
    WHERE u.role = 'professeur' AND u.filiere_id = ?
    AND s.date_soutenance >= CURDATE()
    GROUP BY u.id
    ORDER BY nb_jurys ASC
");
$stmt->execute([$user['filiere_id']]);
$stats_profs = $stmt->fetchAll();
$stats_map = [];
foreach ($stats_profs as $sp) {
    $stats_map[$sp['id']] = $sp['nb_jurys'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Constitution des Jurys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-people"></i> Constitution des Jurys</h1>
            <div>
                <a href="../dashboards/coordinateur.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Retour
                </a>
                <a href="constituer_auto.php" class="btn btn-primary">
                    <i class="bi bi-lightning-charge"></i> Constitution automatique
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $type_message === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> Jury constitué avec succès
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <i class="bi bi-bar-chart"></i> Charge des professeurs (jurys à venir)
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($stats_profs as $stat): ?>
                        <div class="col-md-3 mb-2">
                            <div class="border rounded p-2">
                                <strong><?= htmlspecialchars($stat['prenom'] . ' ' . $stat['nom']) ?></strong>
                                <span class="badge bg-<?= $stat['nb_jurys'] > 5 ? 'danger' : ($stat['nb_jurys'] > 3 ? 'warning' : 'success') ?> float-end">
                                    <?= $stat['nb_jurys'] ?> jurys
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Liste des soutenances -->
        <?php if (count($soutenances) > 0): ?>
            <?php foreach ($soutenances as $sout): ?>
                <?php
                // Récupérer les membres actuels du jury
                $stmt = $pdo->prepare("
                    SELECT j.*, u.nom, u.prenom, j.role_jury
                    FROM jurys j
                    JOIN utilisateurs u ON j.professeur_id = u.id
                    WHERE j.soutenance_id = ?
                ");
                $stmt->execute([$sout['id']]);
                $membres_actuels = $stmt->fetchAll();
                
                $has_president = false;
                $has_examinateur = false;
                foreach ($membres_actuels as $m) {
                    if ($m['role_jury'] === 'president') $has_president = true;
                    if ($m['role_jury'] === 'examinateur') $has_examinateur = true;
                }
                
                $membres_ids = array_column($membres_actuels, 'professeur_id');
                ?>
                
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="mb-0">
                                    <i class="bi bi-calendar-event"></i>
                                    <?= formater_date($sout['date_soutenance'], 'd M Y') ?> à 
                                    <?= date('H:i', strtotime($sout['heure_debut'])) ?>
                                </h5>
                                <p class="mb-0 text-muted">
                                    <strong><?= htmlspecialchars($sout['etudiant_nom']) ?></strong> - 
                                    <?= htmlspecialchars($sout['titre']) ?><br>
                                    <i class="bi bi-geo-alt"></i> <?= htmlspecialchars($sout['salle_nom']) ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <span class="badge bg-<?= $sout['nb_membres'] >= 3 ? 'success' : 'warning' ?> fs-6">
                                    <?= $sout['nb_membres'] ?>/3 membres
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Membres actuels -->
                        <?php if (count($membres_actuels) > 0): ?>
                            <div class="mb-3">
                                <h6><i class="bi bi-people-fill"></i> Membres actuels :</h6>
                                <div class="row">
                                    <?php foreach ($membres_actuels as $membre): ?>
                                        <div class="col-md-4 mb-2">
                                            <div class="border rounded p-2 bg-light">
                                                <strong><?= htmlspecialchars($membre['prenom'] . ' ' . $membre['nom']) ?></strong><br>
                                                <small class="text-muted">
                                                    <span class="badge bg-secondary"><?= ucfirst($membre['role_jury']) ?></span>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Formulaire d'ajout -->
                        <?php if ($sout['nb_membres'] < 3): ?>
                            <form method="POST" class="border-top pt-3">
                                <input type="hidden" name="soutenance_id" value="<?= $sout['id'] ?>">
                                <input type="hidden" name="action" value="ajouter">
                                
                                <div class="row">
                                    <!-- Président -->
                                    <?php if (!$has_president): ?>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                <i class="bi bi-star-fill"></i> Président du jury *
                                                <small class="text-muted">(Ne peut pas être l'encadrant)</small>
                                            </label>
                                            <select name="president_id" class="form-select" required>
                                                <option value="">-- Sélectionner --</option>
                                                <?php foreach ($professeurs as $prof): ?>
                                                    <?php if ($prof['id'] != $sout['encadrant_id'] && !in_array($prof['id'], $membres_ids)): ?>
                                                        <option value="<?= $prof['id'] ?>">
                                                            <?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?>
                                                            (<?= $stats_map[$prof['id']] ?? 0 ?> jurys)
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Examinateur -->
                                    <?php if (!$has_examinateur): ?>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                <i class="bi bi-person"></i> Examinateur *
                                            </label>
                                            <select name="examinateur_id" class="form-select" required>
                                                <option value="">-- Sélectionner --</option>
                                                <?php foreach ($professeurs as $prof): ?>
                                                    <?php if (!in_array($prof['id'], $membres_ids)): ?>
                                                        <option value="<?= $prof['id'] ?>">
                                                            <?= htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']) ?>
                                                            (<?= $stats_map[$prof['id']] ?? 0 ?> jurys)
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-plus-circle"></i> Ajouter au jury
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-success mb-0">
                                <i class="bi bi-check-circle"></i> Jury complet (3 membres)
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Toutes les soutenances ont un jury complet ou aucune soutenance planifiée.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>