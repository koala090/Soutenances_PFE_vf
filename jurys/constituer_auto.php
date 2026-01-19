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
$details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generer') {
    try {
        $pdo->beginTransaction();
        
        // Récupérer toutes les soutenances sans jury complet
        $stmt = $pdo->prepare("
            SELECT s.id as soutenance_id, s.date_soutenance, s.heure_debut, s.heure_fin,
                   p.id as projet_id, p.encadrant_id, p.filiere_id,
                   COUNT(j.id) as nb_membres
            FROM soutenances s
            JOIN projets p ON s.projet_id = p.id
            LEFT JOIN jurys j ON s.id = j.soutenance_id
            WHERE p.filiere_id = ? AND s.statut IN ('planifiee', 'confirmee')
            GROUP BY s.id
            HAVING nb_membres < 3
            ORDER BY s.date_soutenance, s.heure_debut
        ");
        $stmt->execute([$user['filiere_id']]);
        $soutenances = $stmt->fetchAll();
        
        if (count($soutenances) === 0) {
            throw new Exception("Aucune soutenance ne nécessite la constitution de jury");
        }
        
        // Récupérer tous les professeurs avec leurs charges
        $stmt = $pdo->prepare("
            SELECT u.id, u.nom, u.prenom,
                   COUNT(j.id) as nb_jurys_actuels
            FROM utilisateurs u
            LEFT JOIN jurys j ON u.id = j.professeur_id
            LEFT JOIN soutenances s ON j.soutenance_id = s.id
            LEFT JOIN projets p ON s.projet_id = p.id
            WHERE u.role = 'professeur' AND u.filiere_id = ?
            AND (s.date_soutenance >= CURDATE() OR s.date_soutenance IS NULL)
            GROUP BY u.id
            ORDER BY nb_jurys_actuels ASC
        ");
        $stmt->execute([$user['filiere_id']]);
        $professeurs = $stmt->fetchAll();
        
        if (count($professeurs) < 2) {
            throw new Exception("Pas assez de professeurs disponibles (minimum 2 requis)");
        }
        
        // Créer un tableau de charges
        $charges = [];
        foreach ($professeurs as $prof) {
            $charges[$prof['id']] = intval($prof['nb_jurys_actuels']);
        }
        
        $nb_constitues = 0;
        
        foreach ($soutenances as $sout) {
            $soutenance_id = $sout['soutenance_id'];
            $encadrant_id = $sout['encadrant_id'];
            
            // Récupérer les membres déjà présents
            $stmt = $pdo->prepare("SELECT professeur_id, role_jury FROM jurys WHERE soutenance_id = ?");
            $stmt->execute([$soutenance_id]);
            $membres_existants = $stmt->fetchAll();
            
            $membres_ids = array_column($membres_existants, 'professeur_id');
            $has_president = false;
            $has_examinateur = false;
            
            foreach ($membres_existants as $m) {
                if ($m['role_jury'] === 'president') $has_president = true;
                if ($m['role_jury'] === 'examinateur') $has_examinateur = true;
            }
            
            // Ajouter l'encadrant si pas déjà présent
            if (!in_array($encadrant_id, $membres_ids)) {
                $stmt = $pdo->prepare("
                    INSERT INTO jurys (soutenance_id, professeur_id, role_jury) 
                    VALUES (?, ?, 'encadrant')
                ");
                $stmt->execute([$soutenance_id, $encadrant_id]);
                $membres_ids[] = $encadrant_id;
            }
            
            // Trouver un président (pas l'encadrant)
            if (!$has_president) {
                $candidats = [];
                foreach ($professeurs as $prof) {
                    if ($prof['id'] != $encadrant_id && !in_array($prof['id'], $membres_ids)) {
                        $candidats[] = [
                            'id' => $prof['id'],
                            'charge' => $charges[$prof['id']]
                        ];
                    }
                }
                
                // Trier par charge croissante
                usort($candidats, fn($a, $b) => $a['charge'] - $b['charge']);
                
                if (count($candidats) > 0) {
                    $president_id = $candidats[0]['id'];
                    $stmt = $pdo->prepare("
                        INSERT INTO jurys (soutenance_id, professeur_id, role_jury) 
                        VALUES (?, ?, 'president')
                    ");
                    $stmt->execute([$soutenance_id, $president_id]);
                    $membres_ids[] = $president_id;
                    $charges[$president_id]++;
                }
            }
            
            // Trouver un examinateur
            if (!$has_examinateur) {
                $candidats = [];
                foreach ($professeurs as $prof) {
                    if (!in_array($prof['id'], $membres_ids)) {
                        $candidats[] = [
                            'id' => $prof['id'],
                            'charge' => $charges[$prof['id']]
                        ];
                    }
                }
                
                usort($candidats, fn($a, $b) => $a['charge'] - $b['charge']);
                
                if (count($candidats) > 0) {
                    $examinateur_id = $candidats[0]['id'];
                    $stmt = $pdo->prepare("
                        INSERT INTO jurys (soutenance_id, professeur_id, role_jury) 
                        VALUES (?, ?, 'examinateur')
                    ");
                    $stmt->execute([$soutenance_id, $examinateur_id]);
                    $membres_ids[] = $examinateur_id;
                    $charges[$examinateur_id]++;
                }
            }
            
            $nb_constitues++;
        }
        
        $pdo->commit();
        $message = "Constitution automatique réussie : $nb_constitues jury(s) constitué(s)";
        $type_message = 'success';
        
        // Rediriger vers la page de constitution
        header("Location: constituer.php?success=1");
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "Erreur : " . $e->getMessage();
        $type_message = 'error';
    }
}

// Statistiques pour prévisualisation
$stmt = $pdo->prepare("
    SELECT COUNT(*) as nb
    FROM soutenances s
    JOIN projets p ON s.projet_id = p.id
    LEFT JOIN jurys j ON s.id = j.soutenance_id
    WHERE p.filiere_id = ? AND s.statut IN ('planifiee', 'confirmee')
    GROUP BY s.id
    HAVING COUNT(j.id) < 3
");
$stmt->execute([$user['filiere_id']]);
$nb_soutenances_incomplets = $stmt->rowCount();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM utilisateurs 
    WHERE role = 'professeur' AND filiere_id = ?
");
$stmt->execute([$user['filiere_id']]);
$nb_professeurs = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Constitution Automatique des Jurys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="bi bi-lightning-charge"></i> Constitution Automatique des Jurys</h1>
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

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Fonctionnement</h5>
                    </div>
                    <div class="card-body">
                        <h6>Algorithme de constitution automatique :</h6>
                        <ol>
                            <li><strong>Encadrant</strong> : Ajouté automatiquement au jury avec le rôle "encadrant"</li>
                            <li><strong>Président</strong> : 
                                <ul>
                                    <li>Ne peut PAS être l'encadrant du projet</li>
                                    <li>Sélectionné parmi les professeurs ayant le moins de jurys</li>
                                </ul>
                            </li>
                            <li><strong>Examinateur</strong> : 
                                <ul>
                                    <li>Sélectionné parmi les professeurs disponibles</li>
                                    <li>Équilibrage de la charge (moins de jurys en priorité)</li>
                                </ul>
                            </li>
                        </ol>

                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle"></i> <strong>Règles appliquées :</strong>
                            <ul class="mb-0">
                                <li>Minimum 3 membres par jury</li>
                                <li>L'encadrant ne peut pas être président</li>
                                <li>Équilibrage automatique de la charge entre professeurs</li>
                                <li>Pas de doublon dans un jury</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Attention</h5>
                    </div>
                    <div class="card-body">
                        <p>
                            <i class="bi bi-info-circle"></i> Cette action va constituer automatiquement tous les jurys incomplets.
                        </p>
                        <p class="mb-0">
                            <i class="bi bi-check-circle text-success"></i> Les membres déjà ajoutés manuellement seront conservés.
                        </p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-graph-up"></i> Statistiques</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <h6>Soutenances sans jury complet :</h6>
                            <h2 class="text-primary"><?= $nb_soutenances_incomplets ?></h2>
                        </div>
                        
                        <div class="mb-3">
                            <h6>Professeurs disponibles :</h6>
                            <h2 class="text-success"><?= $nb_professeurs ?></h2>
                        </div>

                        <?php if ($nb_soutenances_incomplets > 0 && $nb_professeurs >= 2): ?>
                            <form method="POST" onsubmit="return confirm('Confirmer la constitution automatique de <?= $nb_soutenances_incomplets ?> jury(s) ?')">
                                <input type="hidden" name="action" value="generer">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-lightning-charge"></i>
                                    Lancer la constitution automatique
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">
                                <?php if ($nb_soutenances_incomplets === 0): ?>
                                    Aucun jury à constituer
                                <?php else: ?>
                                    Pas assez de professeurs (minimum 2)
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>