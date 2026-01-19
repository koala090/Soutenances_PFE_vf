<?php
// planning/saisir_dispo.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();

// Vérifier le rôle (coordinateur OU professeur)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['coordinateur', 'professeur'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$user = get_user_info();

// Si c'est un coordinateur, rediriger vers la page de suivi
if ($user['role'] === 'coordinateur') {
    // Récupérer la filière
    $stmt = $pdo->prepare("SELECT filiere_id FROM utilisateurs WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $filiere_id = $stmt->fetchColumn();
    
    $annee_universitaire = "2024-2025";
    
    // Récupérer la période active
    $stmt = $pdo->prepare("
        SELECT * FROM periodes_disponibilite 
        WHERE filiere_id = ? 
        AND annee_universitaire = ?
        AND statut IN ('en_cours', 'a_venir')
        ORDER BY date_creation DESC
        LIMIT 1
    ");
    $stmt->execute([$user['filiere_id'], $annee_universitaire]);
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Rediriger vers la page de suivi pour coordinateur
    header("Location: saisir_dispo.php");
    exit;
}

// Si c'est un professeur, traiter la saisie
$professeur_id = $_SESSION['user_id'];
$user = get_user_info();

// Récupérer la période active
$stmt = $pdo->prepare("
    SELECT * FROM periodes_disponibilite 
    WHERE filiere_id = ? 
    AND statut IN ('en_cours', 'a_venir')
    AND CURDATE() BETWEEN date_debut_saisie AND date_fin_saisie
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute([$user['filiere_id']]);
$periode = $stmt->fetch(PDO::FETCH_ASSOC);

// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ajouter' && $periode) {
        $date_disponible = $_POST['date_disponible'];
        $heure_debut = $_POST['heure_debut'];
        $heure_fin = $_POST['heure_fin'];
        
        if (strtotime($heure_debut) >= strtotime($heure_fin)) {
            $_SESSION['error'] = "L'heure de fin doit être après l'heure de début";
        } elseif ($date_disponible < $periode['date_debut_soutenances'] || 
                  $date_disponible > $periode['date_fin_soutenances']) {
            $_SESSION['error'] = "La date doit être dans la période des soutenances";
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM disponibilites 
                    WHERE professeur_id = ? AND periode_id = ? 
                    AND date_disponible = ? AND heure_debut = ?
                ");
                $stmt->execute([$professeur_id, $periode['id'], $date_disponible, $heure_debut]);
                
                if ($stmt->fetchColumn() > 0) {
                    $_SESSION['error'] = "Cette disponibilité existe déjà";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO disponibilites (professeur_id, periode_id, date_disponible, heure_debut, heure_fin)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$professeur_id, $periode['id'], $date_disponible, $heure_debut, $heure_fin]);
                    $_SESSION['success'] = "Disponibilité ajoutée avec succès !";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Erreur : " . $e->getMessage();
            }
        }
        header("Location: saisir_disponibilites.php");
        exit;
    }
    
    if ($_POST['action'] === 'supprimer') {
        $dispo_id = $_POST['dispo_id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM disponibilites WHERE id = ? AND professeur_id = ?");
            $stmt->execute([$dispo_id, $professeur_id]);
            $_SESSION['success'] = "Disponibilité supprimée !";
        } catch (Exception $e) {
            $_SESSION['error'] = "Erreur : " . $e->getMessage();
        }
        header("Location: saisir_disponibilites.php");
        exit;
    }
}

// Récupérer les disponibilités
$disponibilites = [];
if ($periode) {
    $stmt = $pdo->prepare("
        SELECT * FROM disponibilites 
        WHERE professeur_id = ? AND periode_id = ?
        ORDER BY date_disponible, heure_debut
    ");
    $stmt->execute([$professeur_id, $periode['id']]);
    $disponibilites = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupérer les jurys
$stmt = $pdo->prepare("
    SELECT s.*, p.titre, sal.nom as salle_nom,
           CONCAT(e.prenom, ' ', e.nom) as etudiant_nom, j.role_jury
    FROM jurys j
    INNER JOIN soutenances s ON j.soutenance_id = s.id
    INNER JOIN projets p ON s.projet_id = p.id
    INNER JOIN salles sal ON s.salle_id = sal.id
    INNER JOIN utilisateurs e ON p.etudiant_id = e.id
    WHERE j.professeur_id = ? AND s.statut IN ('planifiee', 'confirmee')
    ORDER BY s.date_soutenance, s.heure_debut
");
$stmt->execute([$professeur_id]);
$mes_jurys = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Disponibilités</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f1f5f9; }
        
        .navbar { background: #1e293b; color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .navbar h2 { font-size: 1.5rem; margin: 0; }
        .navbar a { color: white; text-decoration: none; padding: 8px 16px; border-radius: 6px; transition: background 0.3s; }
        .navbar a:hover { background: rgba(255,255,255,0.1); }
        
        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }
        
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        
        .grid-2 { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; }
        
        .card { background: white; border-radius: 12px; padding: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .card h3 { margin-bottom: 20px; color: #1e293b; display: flex; align-items: center; gap: 10px; font-size: 1.125rem; font-weight: 600; }
        .card h3 i { color: #10b981; }
        
        .periode-info { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-left: 4px solid #10b981; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .periode-info h4 { color: #065f46; margin-bottom: 10px; font-size: 1rem; }
        .periode-info p { color: #065f46; margin: 5px 0; font-size: 0.875rem; }
        
        .form-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 14px; }
        .form-group input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; transition: all 0.3s; }
        .form-group input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1); }
        
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-primary { background: #10b981; color: white; }
        .btn-primary:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4); }
        .btn-danger { background: #dc2626; color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
        .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 20px; }
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge-president { background: #fef3c7; color: #92400e; }
        .badge-encadrant { background: #dbeafe; color: #1e40af; }
        .badge-examinateur { background: #d1fae5; color: #065f46; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat-box { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding: 20px; border-radius: 10px; text-align: center; border: 1px solid #e2e8f0; }
        .stat-number { font-size: 32px; font-weight: bold; color: #10b981; margin-bottom: 5px; }
        .stat-label { font-size: 13px; color: #64748b; }
        
        .creneau-item { background: #f8fafc; padding: 12px 15px; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #e2e8f0; transition: all 0.3s; }
        .creneau-item:hover { background: white; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .creneau-info { flex: 1; }
        .creneau-date { font-weight: 600; color: #1e293b; margin-bottom: 4px; font-size: 14px; }
        .creneau-time { color: #64748b; font-size: 13px; }
        
        .jury-card { background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 15px; margin-bottom: 15px; transition: all 0.3s; }
        .jury-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .jury-title { font-weight: 600; color: #1e293b; margin-bottom: 5px; font-size: 14px; }
        .jury-meta { font-size: 13px; color: #64748b; line-height: 1.6; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📅 Mes Disponibilités</h2>
        <div>
            <a href="../dashboards/professeur.php">← Retour au tableau de bord</a>
        </div>
    </div>

    <div class="container">
        <!-- Messages -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="bi bi-x-circle-fill"></i>
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (!$periode): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill"></i>
                Aucune période de saisie n'est actuellement ouverte. Contactez votre coordinateur.
            </div>
        <?php else: ?>
            <!-- Info période -->
            <div class="periode-info">
                <h4>📆 Période Active</h4>
                <p><strong>Saisie ouverte jusqu'au :</strong> <?= date('d/m/Y', strtotime($periode['date_fin_saisie'])) ?></p>
                <p><strong>Soutenances prévues :</strong> du <?= date('d/m/Y', strtotime($periode['date_debut_soutenances'])) ?> au <?= date('d/m/Y', strtotime($periode['date_fin_soutenances'])) ?></p>
            </div>

            <div class="grid-2">
                <!-- Section principale -->
                <div>
                    <!-- Formulaire -->
                    <div class="card">
                        <h3><i class="bi bi-plus-circle"></i> Ajouter une Disponibilité</h3>
                        
                        <form method="POST">
                            <input type="hidden" name="action" value="ajouter">
                            
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Date *</label>
                                    <input type="date" name="date_disponible" required
                                           min="<?= $periode['date_debut_soutenances'] ?>"
                                           max="<?= $periode['date_fin_soutenances'] ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label>Heure de début *</label>
                                    <input type="time" name="heure_debut" required value="09:00">
                                </div>
                                
                                <div class="form-group">
                                    <label>Heure de fin *</label>
                                    <input type="time" name="heure_fin" required value="12:00">
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> Ajouter cette disponibilité
                            </button>
                        </form>
                    </div>

                    <!-- Liste -->
                    <div class="card">
                        <h3><i class="bi bi-clock-history"></i> Mes Disponibilités Saisies</h3>
                        
                        <?php if (empty($disponibilites)): ?>
                            <div class="empty-state">
                                <i class="bi bi-calendar-x"></i>
                                <p>Vous n'avez pas encore saisi de disponibilités</p>
                            </div>
                        <?php else: ?>
                            <?php
                            $dispos_par_date = [];
                            foreach ($disponibilites as $dispo) {
                                $date = $dispo['date_disponible'];
                                if (!isset($dispos_par_date[$date])) {
                                    $dispos_par_date[$date] = [];
                                }
                                $dispos_par_date[$date][] = $dispo;
                            }
                            ksort($dispos_par_date);
                            
                            // Jours en français
                            $jours_fr = ['Sunday' => 'Dimanche', 'Monday' => 'Lundi', 'Tuesday' => 'Mardi', 
                                         'Wednesday' => 'Mercredi', 'Thursday' => 'Jeudi', 'Friday' => 'Vendredi', 
                                         'Saturday' => 'Samedi'];
                            $mois_fr = ['January' => 'Janvier', 'February' => 'Février', 'March' => 'Mars', 
                                        'April' => 'Avril', 'May' => 'Mai', 'June' => 'Juin', 
                                        'July' => 'Juillet', 'August' => 'Août', 'September' => 'Septembre', 
                                        'October' => 'Octobre', 'November' => 'Novembre', 'December' => 'Décembre'];
                            ?>
                            
                            <?php foreach ($dispos_par_date as $date => $dispos): ?>
                                <?php
                                $jour = $jours_fr[date('l', strtotime($date))];
                                $num = date('d', strtotime($date));
                                $mois = $mois_fr[date('F', strtotime($date))];
                                $annee = date('Y', strtotime($date));
                                ?>
                                <h4 style="margin-top: 20px; margin-bottom: 10px; color: #475569; font-size: 14px; font-weight: 600;">
                                    📅 <?= "$jour $num $mois $annee" ?>
                                </h4>
                                
                                <?php foreach ($dispos as $dispo): ?>
                                    <div class="creneau-item">
                                        <div class="creneau-info">
                                            <div class="creneau-time">
                                                🕐 <?= date('H:i', strtotime($dispo['heure_debut'])) ?> 
                                                - <?= date('H:i', strtotime($dispo['heure_fin'])) ?>
                                            </div>
                                        </div>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="dispo_id" value="<?= $dispo['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Supprimer cette disponibilité ?')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sidebar -->
                <div>
                    <!-- Stats -->
                    <div class="card">
                        <h3><i class="bi bi-graph-up"></i> Statistiques</h3>
                        
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number"><?= count($disponibilites) ?></div>
                                <div class="stat-label">Créneaux</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?= count(array_unique(array_column($disponibilites, 'date_disponible'))) ?></div>
                                <div class="stat-label">Jours</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?= count($mes_jurys) ?></div>
                                <div class="stat-label">Jurys</div>
                            </div>
                        </div>
                    </div>

                    <!-- Jurys -->
                    <div class="card">
                        <h3><i class="bi bi-people"></i> Mes Jurys Planifiés</h3>
                        
                        <?php if (empty($mes_jurys)): ?>
                            <div class="empty-state" style="padding: 40px 20px;">
                                <i class="bi bi-calendar-event" style="font-size: 48px;"></i>
                                <p>Aucun jury planifié</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($mes_jurys as $jury): ?>
                                <div class="jury-card">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                        <div class="jury-title"><?= htmlspecialchars($jury['titre']) ?></div>
                                        <span class="badge badge-<?= $jury['role_jury'] ?>">
                                            <?= ucfirst($jury['role_jury']) ?>
                                        </span>
                                    </div>
                                    <div class="jury-meta">
                                        👨‍🎓 <?= htmlspecialchars($jury['etudiant_nom']) ?><br>
                                        📅 <?= date('d/m/Y', strtotime($jury['date_soutenance'])) ?>
                                        à <?= date('H:i', strtotime($jury['heure_debut'])) ?><br>
                                        🏢 <?= htmlspecialchars($jury['salle_nom']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
