<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['professeur']);

$user = get_user_info();

// Récupérer tous les jurys du professeur
$stmt = $pdo->prepare("
    SELECT s.*, j.role_jury, j.note_attribuee, j.appreciation, j.convocation_envoyee,
           p.titre as projet_titre, p.id as projet_id,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           CONCAT(b.prenom, ' ', b.nom) as binome_nom,
           f.nom as filiere_nom,
           sa.nom as salle_nom, sa.batiment, sa.etage
    FROM jurys j
    JOIN soutenances s ON j.soutenance_id = s.id
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    LEFT JOIN utilisateurs b ON p.binome_id = b.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    WHERE j.professeur_id = ?
    ORDER BY s.date_soutenance DESC, s.heure_debut DESC
");
$stmt->execute([$user['id']]);
$mes_jurys = $stmt->fetchAll();

// Séparer en "à venir" et "passées"
$jurys_a_venir = [];
$jurys_passes = [];

foreach ($mes_jurys as $jury) {
    if ($jury['date_soutenance'] >= date('Y-m-d')) {
        $jurys_a_venir[] = $jury;
    } else {
        $jurys_passes[] = $jury;
    }
}

// Traitement notation
$message = '';
$type_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $soutenance_id = intval($_POST['soutenance_id']);
    
    if ($_POST['action'] === 'noter') {
        $note = floatval($_POST['note']);
        $appreciation = trim($_POST['appreciation']);
        
        if ($note >= 0 && $note <= 20) {
            $stmt = $pdo->prepare("
                UPDATE jurys 
                SET note_attribuee = ?, appreciation = ?, date_notation = NOW()
                WHERE soutenance_id = ? AND professeur_id = ?
            ");
            $stmt->execute([$note, $appreciation, $soutenance_id, $user['id']]);
            
            $message = "Note enregistrée avec succès";
            $type_message = 'success';
            
            // Recharger
            header("Location: mes-jurys.php?success=1");
            exit;
        } else {
            $message = "La note doit être entre 0 et 20";
            $type_message = 'error';
        }
    }
}

$roles_fr = [
    'president' => 'Président du jury',
    'encadrant' => 'Encadrant',
    'examinateur' => 'Examinateur',
    'rapporteur' => 'Rapporteur'
];

$statut_fr = [
    'planifiee' => 'Planifiée',
    'confirmee' => 'Confirmée',
    'en_cours' => 'En cours',
    'terminee' => 'Terminée',
    'reportee' => 'Reportée',
    'annulee' => 'Annulée'
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Jurys - PFE Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-people"></i> Mes Jurys de Soutenance</h1>
            <a href="../dashboards/professeur.php" class="btn btn-secondary">
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
                <i class="bi bi-check-circle"></i> Note enregistrée avec succès
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-primary"><?= count($jurys_a_venir) ?></h3>
                        <p class="text-muted mb-0">Soutenances à venir</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success"><?= count($jurys_passes) ?></h3>
                        <p class="text-muted mb-0">Soutenances passées</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info"><?= count($mes_jurys) ?></h3>
                        <p class="text-muted mb-0">Total cette année</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Soutenances à venir -->
        <?php if (count($jurys_a_venir) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-calendar-event"></i> 
                        Soutenances à venir (<?= count($jurys_a_venir) ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach ($jurys_a_venir as $jury): ?>
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h6 class="mb-0">
                                            <i class="bi bi-calendar3"></i>
                                            <?= formater_date($jury['date_soutenance'], 'd M Y') ?> à 
                                            <?= date('H:i', strtotime($jury['heure_debut'])) ?>
                                        </h6>
                                        <small class="text-muted">
                                            <i class="bi bi-geo-alt"></i> 
                                            <?= htmlspecialchars($jury['salle_nom']) ?>
                                            <?php if ($jury['batiment']): ?>
                                                - <?= htmlspecialchars($jury['batiment']) ?>
                                                <?php if ($jury['etage']): ?>(Étage <?= htmlspecialchars($jury['etage']) ?>)<?php endif; ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <span class="badge bg-<?= $jury['statut'] === 'confirmee' ? 'success' : 'info' ?> fs-6">
                                            <?= $statut_fr[$jury['statut']] ?>
                                        </span>
                                        <?php if ($jury['convocation_envoyee']): ?>
                                            <span class="badge bg-secondary"><i class="bi bi-envelope-check"></i> Convoqué</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h5><?= htmlspecialchars($jury['projet_titre']) ?></h5>
                                        <p class="mb-2">
                                            <strong>Étudiant(s) :</strong> 
                                            <?= htmlspecialchars($jury['etudiant_nom']) ?>
                                            <?php if ($jury['binome_nom']): ?>
                                                & <?= htmlspecialchars($jury['binome_nom']) ?>
                                            <?php endif; ?>
                                        </p>
                                        <p class="mb-2">
                                            <strong>Filière :</strong> <?= htmlspecialchars($jury['filiere_nom']) ?>
                                        </p>
                                        <p class="mb-0">
                                            <strong>Votre rôle :</strong> 
                                            <span class="badge bg-primary"><?= $roles_fr[$jury['role_jury']] ?></span>
                                        </p>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <?php
                                        // Vérifier si le rapport existe
                                        $stmt_rapport = $pdo->prepare("
                                            SELECT * FROM rapports 
                                            WHERE projet_id = ? 
                                            ORDER BY version DESC 
                                            LIMIT 1
                                        ");
                                        $stmt_rapport->execute([$jury['projet_id']]);
                                        $rapport = $stmt_rapport->fetch();
                                        ?>
                                        
                                        <?php if ($rapport): ?>
                                            <a href="../uploads/rapports/<?= htmlspecialchars($rapport['chemin']) ?>" 
                                               class="btn btn-success btn-sm mb-2" 
                                               download
                                               target="_blank">
                                                <i class="bi bi-file-earmark-pdf"></i> Télécharger le rapport
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="../documents/convocation.php?soutenance_id=<?= $jury['id'] ?>&action=generer" 
                                           class="btn btn-outline-primary btn-sm"
                                           target="_blank">
                                            <i class="bi bi-printer"></i> Ma convocation
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Soutenances passées -->
        <?php if (count($jurys_passes) > 0): ?>
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history"></i> 
                        Soutenances passées (<?= count($jurys_passes) ?>)
                    </h5>
                </div>
                <div class="card-body">
                    <?php foreach ($jurys_passes as $jury): ?>
                        <div class="card mb-3">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <h6 class="mb-0">
                                            <?= formater_date($jury['date_soutenance'], 'd M Y') ?> - 
                                            <?= htmlspecialchars($jury['etudiant_nom']) ?>
                                        </h6>
                                        <small class="text-muted"><?= $roles_fr[$jury['role_jury']] ?></small>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <?php if ($jury['note_attribuee']): ?>
                                            <span class="badge bg-success">
                                                <i class="bi bi-check-circle"></i> Noté : <?= number_format($jury['note_attribuee'], 2) ?>/20
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">En attente de notation</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <h6><?= htmlspecialchars($jury['projet_titre']) ?></h6>
                                
                                <?php if (!$jury['note_attribuee']): ?>
                                    <!-- Formulaire de notation -->
                                    <form method="POST" class="mt-3">
                                        <input type="hidden" name="soutenance_id" value="<?= $jury['id'] ?>">
                                        <input type="hidden" name="action" value="noter">
                                        
                                        <div class="row">
                                            <div class="col-md-3">
                                                <label class="form-label">Note (sur 20) *</label>
                                                <input type="number" 
                                                       name="note" 
                                                       class="form-control" 
                                                       step="0.5" 
                                                       min="0" 
                                                       max="20" 
                                                       required>
                                            </div>
                                            <div class="col-md-7">
                                                <label class="form-label">Appréciation</label>
                                                <textarea name="appreciation" 
                                                          class="form-control" 
                                                          rows="2"
                                                          placeholder="Commentaires sur la présentation et le travail..."></textarea>
                                            </div>
                                            <div class="col-md-2 d-flex align-items-end">
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="bi bi-save"></i> Enregistrer
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <!-- Afficher la note déjà saisie -->
                                    <div class="alert alert-success mt-3">
                                        <strong>Votre note :</strong> <?= number_format($jury['note_attribuee'], 2) ?>/20
                                        <?php if ($jury['appreciation']): ?>
                                            <br><strong>Appréciation :</strong> <?= nl2br(htmlspecialchars($jury['appreciation'])) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($jury['note_finale']): ?>
                                    <div class="alert alert-info mt-2">
                                        <strong>Note finale du jury :</strong> <?= number_format($jury['note_finale'], 2) ?>/20
                                        <?php if ($jury['mention']): ?>
                                            - <strong>Mention :</strong> <?= ucfirst(str_replace('_', ' ', $jury['mention'])) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (count($mes_jurys) === 0): ?>
            <div class="alert alert-info text-center">
                <i class="bi bi-info-circle"></i> 
                Vous n'avez pas encore été affecté à un jury de soutenance.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>