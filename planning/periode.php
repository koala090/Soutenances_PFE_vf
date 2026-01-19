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

// Traitement des actions (créer, modifier, supprimer période)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'creer') {
            $stmt = $pdo->prepare("
                INSERT INTO periodes_disponibilite 
                (filiere_id, annee_universitaire, date_debut_saisie, date_fin_saisie, 
                 date_debut_soutenances, date_fin_soutenances, statut)
                VALUES (?, ?, ?, ?, ?, ?, 'a_venir')
            ");
            $stmt->execute([
                $user['filiere_id'],
                $_POST['annee_universitaire'],
                $_POST['date_debut_saisie'],
                $_POST['date_fin_saisie'],
                $_POST['date_debut_soutenances'],
                $_POST['date_fin_soutenances']
            ]);
            $message = "Période créée avec succès";
        } elseif ($_POST['action'] === 'ouvrir') {
            $stmt = $pdo->prepare("UPDATE periodes_disponibilite SET statut = 'en_cours' WHERE id = ?");
            $stmt->execute([$_POST['periode_id']]);
            $message = "Période ouverte pour la saisie";
        } elseif ($_POST['action'] === 'cloturer') {
            $stmt = $pdo->prepare("UPDATE periodes_disponibilite SET statut = 'cloturee' WHERE id = ?");
            $stmt->execute([$_POST['periode_id']]);
            $message = "Période clôturée";
        }
    }
}

// Récupérer les périodes
$stmt = $pdo->prepare("
    SELECT * FROM periodes_disponibilite 
    WHERE filiere_id = ? 
    ORDER BY date_debut_soutenances DESC
");
$stmt->execute([$user['filiere_id']]);
$periodes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Périodes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
<div class="container mt-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>Gestion des Périodes de Soutenance</h1>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#nouvellePeriodeModal">
            <i class="bi bi-plus-circle"></i> Nouvelle Période
        </button>
    </div>

    <?php if (isset($message)): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Année Universitaire</th>
                    <th>Saisie Disponibilités</th>
                    <th>Période Soutenances</th>
                    <th>Statut</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($periodes as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['annee_universitaire']) ?></td>
                    <td><?= date('d/m/Y', strtotime($p['date_debut_saisie'])) ?> - <?= date('d/m/Y', strtotime($p['date_fin_saisie'])) ?></td>
                    <td><?= date('d/m/Y', strtotime($p['date_debut_soutenances'])) ?> - <?= date('d/m/Y', strtotime($p['date_fin_soutenances'])) ?></td>
                    <td>
                        <?php if ($p['statut'] === 'a_venir'): ?>
                            <span class="badge bg-secondary">À venir</span>
                        <?php elseif ($p['statut'] === 'en_cours'): ?>
                            <span class="badge bg-success">En cours</span>
                        <?php else: ?>
                            <span class="badge bg-dark">Clôturée</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['statut'] === 'a_venir'): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="periode_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="action" value="ouvrir">
                                <button type="submit" class="btn btn-sm btn-success">Ouvrir</button>
                            </form>
                        <?php elseif ($p['statut'] === 'en_cours'): ?>
                            <form method="post" style="display:inline;">
                                <input type="hidden" name="periode_id" value="<?= $p['id'] ?>">
                                <input type="hidden" name="action" value="cloturer">
                                <button type="submit" class="btn btn-sm btn-warning">Clôturer</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <a href="../dashboards/coordinateur.php" class="btn btn-secondary mt-3">
        <i class="bi bi-arrow-left"></i> Retour au tableau de bord
    </a>
</div>

<!-- Modal Nouvelle Période -->
<div class="modal fade" id="nouvellePeriodeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="creer">
                <div class="modal-header">
                    <h5 class="modal-title">Créer une Nouvelle Période</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Année Universitaire</label>
                        <input type="text" class="form-control" name="annee_universitaire" value="2024-2025" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Début Saisie Disponibilités</label>
                        <input type="date" class="form-control" name="date_debut_saisie" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fin Saisie Disponibilités</label>
                        <input type="date" class="form-control" name="date_fin_saisie" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Début Soutenances</label>
                        <input type="date" class="form-control" name="date_debut_soutenances" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fin Soutenances</label>
                        <input type="date" class="form-control" name="date_fin_soutenances" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Créer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
