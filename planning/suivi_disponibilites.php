<?php
// planning/suivi_disponibilites.php - Pour le coordinateur
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();

// Vérifier que c'est un coordinateur
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'coordinateur') {
    header('Location: ../login.php');
    exit;
}

$coordinateur_id = $_SESSION['user_id'];
$user = get_user_info();

// Récupérer la filière
$stmt = $pdo->prepare("SELECT filiere_id FROM utilisateurs WHERE id = ?");
$stmt->execute([$coordinateur_id]);
$filiere_id = $stmt->fetchColumn();

$annee_universitaire = "2024-2025";

// Récupérer la période active
$stmt = $pdo->prepare("
    SELECT * FROM periodes_disponibilite 
    WHERE filiere_id = ? 
    AND annee_universitaire = ?
    AND statut IN ('en_cours', 'a_venir')
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute([$filiere_id, $annee_universitaire]);
$periode = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$periode) {
    $_SESSION['warning'] = "Aucune période active. Créez-en une nouvelle.";
    header("Location: periode.php");
    exit;
}

// Récupérer tous les professeurs de la filière
$stmt = $pdo->prepare("
    SELECT 
        u.id,
        u.nom,
        u.prenom,
        u.email,
        COUNT(DISTINCT d.id) as nb_disponibilites
    FROM utilisateurs u
    LEFT JOIN disponibilites d ON u.id = d.professeur_id AND d.periode_id = ?
    WHERE u.role = 'professeur'
    AND u.filiere_id = ?
    AND u.actif = 1
    GROUP BY u.id
    ORDER BY u.nom, u.prenom
");
$stmt->execute([$periode['id'], $filiere_id]);
$professeurs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_profs = count($professeurs);
$profs_saisis = count(array_filter($professeurs, fn($p) => $p['nb_disponibilites'] > 0));
$profs_manquants = $total_profs - $profs_saisis;

// Traitement de l'envoi de relances
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'relancer') {
    $professeurs_a_relancer = [];
    
    foreach ($professeurs as $prof) {
        if ($prof['nb_disponibilites'] == 0) {
            $professeurs_a_relancer[] = $prof;
        }
    }
    
    // Ici, vous enverriez normalement des emails
    // Pour cet exemple, on simule juste l'envoi
    
    $_SESSION['success'] = "Relances envoyées à " . count($professeurs_a_relancer) . " professeur(s) !";
    header("Location: suivi_disponibilites.php");
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suivi des Disponibilités</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f1f5f9; }
        
        .navbar { background: #1e293b; color: white; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .navbar h2 { font-size: 1.5rem; margin: 0; }
        .navbar a { color: white; text-decoration: none; padding: 8px 16px; border-radius: 6px; transition: background 0.3s; }
        .navbar a:hover { background: rgba(255,255,255,0.1); }
        
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        
        .periode-info { background: #dbeafe; border-left: 4px solid #2563eb; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; }
        .periode-info strong { color: #1e40af; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 42px; font-weight: bold; margin: 10px 0; }
        .stat-number.success { color: #10b981; }
        .stat-number.warning { color: #f59e0b; }
        .stat-number.gray { color: #64748b; }
        .stat-label { color: #64748b; font-size: 14px; }
        
        .progress-bar-wrapper { height: 20px; background: #e2e8f0; border-radius: 10px; overflow: hidden; margin: 15px 0; }
        .progress-fill { background: #10b981; height: 100%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px; font-weight: 600; }
        
        .card { background: white; border-radius: 10px; padding: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .card h3 { margin-bottom: 20px; color: #1e293b; font-size: 1.125rem; font-weight: 600; }
        
        .actions-bar { display: flex; gap: 10px; margin-bottom: 20px; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-secondary:hover { background: #475569; }
        
        .filter-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .filter-tab { padding: 10px 20px; border: none; background: #f1f5f9; color: #64748b; border-radius: 6px; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .filter-tab.active { background: #2563eb; color: white; }
        
        .table { width: 100%; border-collapse: collapse; }
        .table th { background: #f8fafc; padding: 12px; text-align: left; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
        .table td { padding: 12px; border-bottom: 1px solid #e2e8f0; }
        .table tbody tr:hover { background: #f8fafc; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>📅 Suivi des Disponibilités</h2>
        <div>
            <a href="periode.php" style="margin-right: 20px;">Gérer les périodes</a>
            <a href="voir_planning.php">← Retour au planning</a>
        </div>
    </div>

    <div class="container">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                ✓ <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <!-- Info période -->
        <div class="periode-info">
            <strong>📆 Période Active :</strong> 
            Saisie du <?= date('d/m/Y', strtotime($periode['date_debut_saisie'])) ?> 
            au <?= date('d/m/Y', strtotime($periode['date_fin_saisie'])) ?>
            <br>
            <strong>🎓 Soutenances :</strong> 
            Du <?= date('d/m/Y', strtotime($periode['date_debut_soutenances'])) ?> 
            au <?= date('d/m/Y', strtotime($periode['date_fin_soutenances'])) ?>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Professeurs</div>
                <div class="stat-number gray"><?= $total_profs ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Ont Saisi</div>
                <div class="stat-number success"><?= $profs_saisis ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">En Attente</div>
                <div class="stat-number warning"><?= $profs_manquants ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Taux de Réponse</div>
                <div class="stat-number <?= $profs_saisis >= $total_profs * 0.7 ? 'success' : 'warning' ?>">
                    <?= $total_profs > 0 ? round(($profs_saisis / $total_profs) * 100) : 0 ?>%
                </div>
            </div>
        </div>

        <!-- Barre de progression -->
        <div class="card">
            <h3>📊 Progression de la Collecte</h3>
            <div class="progress-bar-wrapper">
                <div class="progress-fill" style="width: <?= $total_profs > 0 ? ($profs_saisis / $total_profs) * 100 : 0 ?>%">
                    <?= $profs_saisis ?> / <?= $total_profs ?>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="actions-bar">
            <?php if ($profs_manquants > 0): ?>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="relancer">
                    <button type="submit" class="btn btn-warning" 
                            onclick="return confirm('Envoyer une relance à <?= $profs_manquants ?> professeur(s) ?')">
                        📧 Relancer les professeurs en attente
                    </button>
                </form>
            <?php endif; ?>
            
            <button class="btn btn-secondary" onclick="window.print()">
                🖨️ Imprimer la liste
            </button>
        </div>

        <!-- Filtres -->
        <div class="filter-tabs">
            <button class="filter-tab active" onclick="filtrer('tous')">
                Tous (<?= $total_profs ?>)
            </button>
            <button class="filter-tab" onclick="filtrer('saisi')">
                Ont saisi (<?= $profs_saisis ?>)
            </button>
            <button class="filter-tab" onclick="filtrer('manquant')">
                En attente (<?= $profs_manquants ?>)
            </button>
        </div>

        <!-- Liste -->
        <div class="card">
            <h3>👥 Liste des Professeurs</h3>
            
            <table class="table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Email</th>
                        <th>Nb de Créneaux</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php foreach ($professeurs as $prof): ?>
                        <tr class="prof-row" data-type="<?= $prof['nb_disponibilites'] > 0 ? 'saisi' : 'manquant' ?>">
                            <td><strong><?= htmlspecialchars($prof['nom']) ?></strong></td>
                            <td><?= htmlspecialchars($prof['prenom']) ?></td>
                            <td><?= htmlspecialchars($prof['email']) ?></td>
                            <td>
                                <strong style="color: <?= $prof['nb_disponibilites'] > 0 ? '#10b981' : '#f59e0b' ?>;">
                                    <?= $prof['nb_disponibilites'] ?>
                                </strong> créneau(x)
                            </td>
                            <td>
                                <?php if ($prof['nb_disponibilites'] > 0): ?>
                                    <span class="badge badge-success">✓ Saisi</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">⏳ En attente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function filtrer(type) {
            const rows = document.querySelectorAll('.prof-row');
            const tabs = document.querySelectorAll('.filter-tab');
            
            // Mettre à jour les tabs
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            // Filtrer les lignes
            rows.forEach(row => {
                if (type === 'tous') {
                    row.style.display = '';
                } else if (type === 'saisi' && row.dataset.type === 'saisi') {
                    row.style.display = '';
                } else if (type === 'manquant' && row.dataset.type === 'manquant') {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
