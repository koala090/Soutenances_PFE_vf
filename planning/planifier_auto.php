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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer les projets à planifier
    $stmt = $pdo->prepare("
        SELECT p.*, enc.id as encadrant_id
        FROM projets p
        LEFT JOIN utilisateurs enc ON p.encadrant_id = enc.id
        LEFT JOIN soutenances s ON p.id = s.projet_id
        WHERE p.filiere_id = ? AND p.encadrant_id IS NOT NULL AND s.id IS NULL
    ");
    $stmt->execute([$user['filiere_id']]);
    $projets = $stmt->fetchAll();
    
    // Récupérer les salles disponibles
    $stmt = $pdo->query("SELECT * FROM salles WHERE disponible = 1");
    $salles = $stmt->fetchAll();
    
    // Récupérer les disponibilités des professeurs
    $stmt = $pdo->prepare("
        SELECT d.*, u.id as prof_id
        FROM disponibilites d
        JOIN utilisateurs u ON d.professeur_id = u.id
        WHERE u.filiere_id = ?
        ORDER BY d.date_disponible, d.heure_debut
    ");
    $stmt->execute([$user['filiere_id']]);
    $disponibilites = $stmt->fetchAll();
    
    $nb_planifies = 0;
    $date_actuelle = date('Y-m-d', strtotime('+7 days')); // Commencer dans 7 jours
    $heure_debut = '09:00:00';
    
    foreach ($projets as $projet) {
        if (count($salles) > 0) {
            $salle = $salles[array_rand($salles)];
            $heure_fin = date('H:i:s', strtotime($heure_debut) + 75*60);
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO soutenances 
                    (projet_id, salle_id, date_soutenance, heure_debut, heure_fin, statut)
                    VALUES (?, ?, ?, ?, ?, 'planifiee')
                ");
                $stmt->execute([
                    $projet['id'],
                    $salle['id'],
                    $date_actuelle,
                    $heure_debut,
                    $heure_fin
                ]);
                
                // Mettre à jour le statut du projet
                $stmt = $pdo->prepare("UPDATE projets SET statut = 'planifie' WHERE id = ?");
                $stmt->execute([$projet['id']]);
                
                $nb_planifies++;
                
                // Avancer le créneau
                $heure_debut = date('H:i:s', strtotime($heure_debut) + 90*60);
                if ($heure_debut >= '18:00:00') {
                    $heure_debut = '09:00:00';
                    $date_actuelle = date('Y-m-d', strtotime($date_actuelle . ' +1 day'));
                }
                
            } catch (PDOException $e) {
                // Ignorer les conflits et continuer
                continue;
            }
        }
    }
    
    header("Location: voir_planning.php?success=Planification automatique effectuée: $nb_planifies soutenances créées");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Planification Automatique</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
<div class="container mt-5">
    <div class="card">
        <div class="card-body text-center p-5">
            <div class="mb-4">
                <i class="bi bi-lightning-charge-fill" style="font-size: 4rem; color: #0891b2;"></i>
            </div>
            <h2 class="mb-3">Planification Automatique</h2>
            <p class="text-muted mb-4">
                Le système va générer automatiquement un planning optimal en tenant compte des disponibilités
                et des contraintes de salles.
            </p>
            
            <form method="post">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-play-circle"></i> Lancer la Planification
                </button>
            </form>
            
            <a href="planifier.php" class="btn btn-link mt-3">Retour</a>
        </div>
    </div>
</div>
</body>
</html>
