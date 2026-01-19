<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
verifier_role(['professeur', 'coordinateur']);

$user = get_user_info();
$message = '';
$type_message = '';

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $soutenance_id = intval($_POST['soutenance_id']);
    
    try {
        $pdo->beginTransaction();
        
        if ($_POST['action'] === 'saisir_notes_membres') {
            // Saisie des notes individuelles des membres du jury
            foreach ($_POST['notes'] as $jury_id => $note) {
                $note_val = floatval($note);
                $appreciation = trim($_POST['appreciations'][$jury_id] ?? '');
                
                if ($note_val >= 0 && $note_val <= 20) {
                    $stmt = $pdo->prepare("
                        UPDATE jurys 
                        SET note_attribuee = ?, appreciation = ?, date_notation = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$note_val, $appreciation, $jury_id]);
                }
            }
            
            $message = "Notes individuelles enregistrées avec succès";
            $type_message = 'success';
        }
        
        if ($_POST['action'] === 'saisir_note_finale') {
            // Saisie de la note finale et observations globales
            $note_finale = floatval($_POST['note_finale']);
            $observations = trim($_POST['observations'] ?? '');
            
            // Déterminer la mention
            $mention = null;
            if ($note_finale >= 16) $mention = 'tres_bien';
            elseif ($note_finale >= 14) $mention = 'bien';
            elseif ($note_finale >= 12) $mention = 'assez_bien';
            elseif ($note_finale >= 10) $mention = 'passable';
            
            $stmt = $pdo->prepare("
                UPDATE soutenances 
                SET note_finale = ?, mention = ?, observations = ?, statut = 'terminee' 
                WHERE id = ?
            ");
            $stmt->execute([$note_finale, $mention, $observations, $soutenance_id]);
            
            $message = "Note finale enregistrée avec succès (Mention: " . ($mention ?? 'Ajourné') . ")";
            $type_message = 'success';
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Erreur : " . $e->getMessage();
        $type_message = 'error';
    }
}

// Récupérer les soutenances
$where_clause = "WHERE s.date_soutenance <= CURDATE() AND s.statut IN ('planifiee', 'confirmee', 'en_cours', 'terminee')";
$params = [];

if ($user['role'] === 'professeur') {
    // Le professeur voit seulement ses jurys
    $where_clause .= " AND j.professeur_id = ?";
    $params[] = $user['id'];
} else {
    // Le coordinateur voit toutes les soutenances de sa filière
    $where_clause .= " AND p.filiere_id = ?";
    $params[] = $user['filiere_id'];
}

$stmt = $pdo->prepare("
    SELECT DISTINCT s.*, p.titre,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           f.nom as filiere_nom,
           sa.nom as salle_nom
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    JOIN utilisateurs e ON p.etudiant_id = e.id
    JOIN filieres f ON p.filiere_id = f.id
    JOIN salles sa ON s.salle_id = sa.id
    LEFT JOIN jurys j ON s.id = j.soutenance_id
    $where_clause
    ORDER BY s.date_soutenance DESC, s.heure_debut DESC
");
$stmt->execute($params);
$soutenances = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saisie des Notes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-pencil-square"></i> Saisie des Notes de Soutenance</h1>
            <a href="../dashboards/<?= $user['role'] ?>.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Retour
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $type_message === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (count($soutenances) > 0): ?>
            <?php foreach ($soutenances as $sout): ?>
                <?php
                // Récupérer les membres du jury
                $stmt = $pdo->prepare("
                    SELECT j.*, u.nom, u.prenom
                    FROM jurys j
                    JOIN utilisateurs u ON j.professeur_id = u.id
                    WHERE j.soutenance_id = ?
                    ORDER BY FIELD(j.role_jury, 'president', 'encadrant', 'examinateur')
                ");
                $stmt->execute([$sout['id']]);
                $jury = $stmt->fetchAll();
                
                $toutes_notes_saisies = true;
                foreach ($jury as $m) {
                    if ($m['note_attribuee'] === null) {
                        $toutes_notes_saisies = false;
                        break;
                    }
                }
                ?>
                
                <div class="card mb-4">
                    <div class="card-header bg-<?= $sout['note_finale'] ? 'success' : 'primary' ?> text-white">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 class="mb-0">
                                    <i class="bi bi-calendar-event"></i>
                                    <?= formater_date($sout['date_soutenance'], 'd M Y') ?> à 
                                    <?= date('H:i', strtotime($sout['heure_debut'])) ?>
                                </h5>
                                <p class="mb-0">
                                    <strong><?= htmlspecialchars($sout['etudiant_nom']) ?></strong> - 
                                    <?= htmlspecialchars($sout['titre']) ?>
                                </p>
                            </div>
                            <div class="col-md-4 text-end">
                                <?php if ($sout['note_finale']): ?>
                                    <span class="badge bg-light text-dark fs-5">
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                        <?= number_format($sout['note_finale'], 2) ?>/20
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">En attente</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-tabs mb-3" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" data-bs-toggle="tab" href="#notes-<?= $sout['id'] ?>">
                                    <i class="bi bi-list-ol"></i> Notes individuelles
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?= !$toutes_notes_saisies ? 'disabled' : '' ?>" 
                                   data-bs-toggle="tab" href="#finale-<?= $sout['id'] ?>">
                                    <i class="bi bi-trophy"></i> Note finale
                                </a>
                            </li>
                        </ul>
                        
                        <div class="tab-content">
                            <!-- Notes individuelles -->
                            <div class="tab-pane fade show active" id="notes-<?= $sout['id'] ?>">
                                <form method="POST">
                                    <input type="hidden" name="soutenance_id" value="<?= $sout['id'] ?>">
                                    <input type="hidden" name="action" value="saisir_notes_membres">
                                    
                                    <?php foreach ($jury as $membre): ?>
                                        <div class="card mb-3">
                                            <div class="card-header bg-light">
                                                <strong><?= htmlspecialchars($membre['prenom'] . ' ' . $membre['nom']) ?></strong>
                                                <span class="badge bg-secondary"><?= ucfirst($membre['role_jury']) ?></span>
                                                <?php if ($membre['note_attribuee']): ?>
                                                    <span class="badge bg-success float-end">
                                                        <i class="bi bi-check-circle"></i> Note saisie
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <label class="form-label">Note (sur 20) *</label>
                                                        <input type="number" 
                                                               name="notes[<?= $membre['id'] ?>]" 
                                                               class="form-control" 
                                                               step="0.5" 
                                                               min="0" 
                                                               max="20"
                                                               value="<?= $membre['note_attribuee'] ?? '' ?>"
                                                               <?= $user['role'] === 'professeur' && $membre['professeur_id'] != $user['id'] ? 'readonly' : '' ?>>
                                                    </div>
                                                    <div class="col-md-9">
                                                        <label class="form-label">Appréciation</label>
                                                        <textarea name="appreciations[<?= $membre['id'] ?>]" 
                                                                  class="form-control" 
                                                                  rows="2"
                                                                  <?= $user['role'] === 'professeur' && $membre['professeur_id'] != $user['id'] ? 'readonly' : '' ?>><?= htmlspecialchars($membre['appreciation'] ?? '') ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Enregistrer les notes individuelles
                                    </button>
                                </form>
                            </div>
                            
                            <!-- Note finale -->
                            <div class="tab-pane fade" id="finale-<?= $sout['id'] ?>">
                                <?php if ($toutes_notes_saisies || $user['role'] === 'coordinateur'): ?>
                                    <?php
                                    // Calculer la moyenne des notes
                                    $moyenne = 0;
                                    $nb_notes = 0;
                                    foreach ($jury as $m) {
                                        if ($m['note_attribuee']) {
                                            $moyenne += $m['note_attribuee'];
                                            $nb_notes++;
                                        }
                                    }
                                    $moyenne = $nb_notes > 0 ? $moyenne / $nb_notes : 0;
                                    ?>
                                    
                                    <div class="alert alert-info mb-3">
                                        <i class="bi bi-calculator"></i> 
                                        <strong>Moyenne calculée :</strong> <?= number_format($moyenne, 2) ?>/20
                                    </div>
                                    
                                    <form method="POST">
                                        <input type="hidden" name="soutenance_id" value="<?= $sout['id'] ?>">
                                        <input type="hidden" name="action" value="saisir_note_finale">
                                        
                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label">Note finale (sur 20) *</label>
                                                <input type="number" 
                                                       name="note_finale" 
                                                       class="form-control form-control-lg" 
                                                       step="0.25" 
                                                       min="0" 
                                                       max="20"
                                                       value="<?= $sout['note_finale'] ?? number_format($moyenne, 2) ?>"
                                                       required>
                                            </div>
                                            <div class="col-md-8 mb-3">
                                                <label class="form-label">Observations du jury</label>
                                                <textarea name="observations" 
                                                          class="form-control" 
                                                          rows="4"><?= htmlspecialchars($sout['observations'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bi bi-check-circle"></i> Valider la note finale
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-warning">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        Toutes les notes individuelles doivent être saisies avant de pouvoir valider la note finale.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Aucune soutenance disponible pour la saisie des notes.
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>