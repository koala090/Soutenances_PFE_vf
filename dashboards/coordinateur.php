<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';

demarrer_session();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'coordinateur') {
    header('Location: ../login.php');
    exit;
}

$user = get_user_info();

// Nom de la filière
$stmt = $pdo->prepare("SELECT nom FROM filieres WHERE id = ?");
$stmt->execute([$user['filiere_id']]);
$filiere = $stmt->fetch();
$nom_filiere = $filiere['nom'] ?? 'Filière';

// Statistiques globales (total projets, sans encadrant, planifiés/soutenues)
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_projets,
        SUM(CASE WHEN encadrant_id IS NULL THEN 1 ELSE 0 END) as sans_encadrant,
        SUM(CASE WHEN statut IN ('planifie', 'soutenu') THEN 1 ELSE 0 END) as planifies
    FROM projets
    WHERE filiere_id = ? AND annee_universitaire = '2024-2025'
");
$stmt->execute([$user['filiere_id']]);
$stats = $stmt->fetch();
$stats['total_projets'] = (int)($stats['total_projets'] ?? 0);
$stats['sans_encadrant'] = (int)($stats['sans_encadrant'] ?? 0);
$stats['planifies'] = (int)($stats['planifies'] ?? 0);

// Jurys à compléter (on considère qu'un jury complet = 3 membres ; ajuste si nécessaire)
$required_jury_size = 3;
$stmt = $pdo->prepare("
    SELECT COUNT(*) as a FROM (
        SELECT s.id, COUNT(j.id) AS nb_membres
        FROM soutenances s
        JOIN projets p ON s.projet_id = p.id
        LEFT JOIN jurys j ON s.id = j.soutenance_id
        WHERE p.filiere_id = ?
        GROUP BY s.id
        HAVING nb_membres < ?
    ) t
");
$stmt->execute([$user['filiere_id'], $required_jury_size]);
$jurys_a_completer = (int)$stmt->fetchColumn();

// Convocations à envoyer (on utilise la colonne jurys.convocation_envoyee)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM jurys j
    JOIN soutenances s ON j.soutenance_id = s.id
    JOIN projets p ON s.projet_id = p.id
    WHERE p.filiere_id = ? AND (j.convocation_envoyee = 0 OR j.convocation_envoyee IS NULL)
");
$stmt->execute([$user['filiere_id']]);
$convocations_a_envoyer = (int)$stmt->fetchColumn();

// Projets sans encadrant (liste, limit 5)
$stmt = $pdo->prepare("
    SELECT p.*, CONCAT(e.prenom, ' ', e.nom) as etudiant_nom,
           DATEDIFF(CURDATE(), p.date_inscription) as jours_attente
    FROM projets p
    JOIN utilisateurs e ON p.etudiant_id = e.id
    WHERE p.filiere_id = ? AND p.encadrant_id IS NULL
    ORDER BY p.date_inscription
    LIMIT 5
");
$stmt->execute([$user['filiere_id']]);
$projets_sans_encadrant = $stmt->fetchAll();

// Projets prêts à être planifiés : encadrant affecté mais pas encore dans la table soutenances
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM projets p
    LEFT JOIN soutenances s ON p.id = s.projet_id
    WHERE p.filiere_id = ? AND p.encadrant_id IS NOT NULL AND s.id IS NULL
");
$stmt->execute([$user['filiere_id']]);
$projets_a_planifier = (int)$stmt->fetchColumn();

// Disponibilités professeurs (nb créneaux par prof)
$stmt = $pdo->prepare("
    SELECT u.id, u.nom, u.prenom,
           COUNT(d.id) as nb_creneaux
    FROM utilisateurs u
    LEFT JOIN disponibilites d ON u.id = d.professeur_id
    WHERE u.role = 'professeur' AND u.filiere_id = ?
    GROUP BY u.id
    ORDER BY u.nom
");
$stmt->execute([$user['filiere_id']]);
$disponibilites_profs = $stmt->fetchAll();

// Progression (projets traités / soutenances planifiées)
$total = $stats['total_projets'] > 0 ? $stats['total_projets'] : 1;
$traites = $total - $stats['sans_encadrant'];
$progression_projets = round(($traites / $total) * 100);
$progression_soutenances = round(($stats['planifies'] / $total) * 100);

// Progression saisie des disponibilités (profs ayant au moins 1 créneau)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE role='professeur' AND filiere_id=?");
$stmt->execute([$user['filiere_id']]);
$total_profs = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT professeur_id) 
    FROM disponibilites 
    WHERE professeur_id IN (SELECT id FROM utilisateurs WHERE role='professeur' AND filiere_id=?)
");
$stmt->execute([$user['filiere_id']]);
$profs_saisis = (int)$stmt->fetchColumn();

$progression_dispos = ($total_profs > 0) ? round(($profs_saisis / $total_profs) * 100) : 0;

// Convocations envoyées / total (pour vue d'ensemble)
$stmt = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN j.convocation_envoyee = 1 THEN 1 ELSE 0 END) AS envoyees,
        COUNT(*) AS total_jurys
    FROM jurys j
    JOIN soutenances s ON j.soutenance_id = s.id
    JOIN projets p ON s.projet_id = p.id
    WHERE p.filiere_id = ?
");
$stmt->execute([$user['filiere_id']]);
$conv = $stmt->fetch();
$convocations_envoyees = (int)($conv['envoyees'] ?? 0);
$total_convocations = (int)($conv['total_jurys'] ?? 0);
$progression_convocations = ($total_convocations > 0) ? round(($convocations_envoyees / $total_convocations) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord Coordinateur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8f9fa; }
        
        .container-fluid { display: flex; min-height: 100vh; padding: 0; }
                .sidebar { 
            width: 240px; 
            background: white; 
            padding: 1.5rem; 
            box-shadow: 2px 0 8px rgba(0,0,0,0.05); 
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
        
        .brand { display: flex; align-items: center; gap: 0.75rem; margin-bottom: 2rem; text-decoration: none; }
        .brand-icon { width: 40px; height: 40px; background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.25rem; }
        .brand-name { font-size: 1.125rem; font-weight: 600; color: #0f172a; }
        
        .nav-menu { list-style: none; padding: 0; }
        .nav-item { margin-bottom: 0.25rem; }
        .nav-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem; color: #64748b; text-decoration: none; border-radius: 8px; font-size: 0.875rem; transition: all 0.2s; }
        .nav-link:hover { background: #f1f5f9; color: #0f172a; transform: translateX(2px); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .nav-link.active { background: #dbeafe; color: #0369a1; font-weight: 500; }
        .nav-link i { font-size: 1.125rem; }
        
        .nav-footer { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #f1f5f9; }
        .nav-footer .nav-link { color: #ef4444; }
        .nav-footer .nav-link:hover { background: #fee2e2; }
        
        .main-content { 
            flex: 1; 
            padding: 2rem; 
            margin-left: 240px;
            width: 100%; 
        }
        
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .page-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin: 0; }
        .page-subtitle { font-size: 0.875rem; color: #64748b; margin-top: 0.25rem; }
        
        .header-actions { display: flex; align-items: center; gap: 1rem; }
        .notification-btn { position: relative; width: 40px; height: 40px; background: white; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; }
        .notification-btn:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .notification-badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: white; font-size: 0.625rem; font-weight: 600; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        
        .user-menu { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 1rem; background: white; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.2s; }
        .user-menu:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.1); transform: translateY(-2px); }
        .user-avatar { width: 36px; height: 36px; background: #a78bfa; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.875rem; }
        .user-info { text-align: left; }
        .user-name { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .user-role { font-size: 0.75rem; color: #64748b; }
        
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; transition: all 0.2s; }
        .stat-card:hover { box-shadow: 0 8px 16px rgba(0,0,0,0.1); transform: translateY(-4px); }
        .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .stat-label { font-size: 0.875rem; color: #64748b; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .stat-icon.yellow { background: #fef3c7; color: #f59e0b; }
        .stat-icon.blue { background: #dbeafe; color: #0284c7; }
        .stat-icon.teal { background: #ccfbf1; color: #0d9488; }
        .stat-icon.cyan { background: #cffafe; color: #0891b2; }
        .stat-value { font-size: 2rem; font-weight: 700; color: #0f172a; margin-bottom: 0.25rem; }
        .stat-sublabel { font-size: 0.875rem; color: #64748b; }
        
        .content-grid { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; }
        
        .section-card { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; transition: all 0.2s; }
        .section-card:hover { box-shadow: 0 6px 12px rgba(0,0,0,0.08); }
        .section-title { font-size: 1.125rem; font-weight: 600; color: #0f172a; margin-bottom: 1.5rem; }
        
        .action-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .action-item { padding: 1.25rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; transition: all 0.2s; }
        .action-item:hover { background: white; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-color: #cbd5e1; transform: translateY(-2px); }
        .action-icon { width: 48px; height: 48px; background: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 0.75rem; font-size: 1.5rem; }
        .action-icon.yellow { color: #f59e0b; }
        .action-icon.blue { color: #0284c7; }
        .action-icon.teal { color: #0d9488; }
        .action-icon.cyan { color: #0891b2; }
        .action-title { font-size: 0.875rem; font-weight: 600; color: #0f172a; margin-bottom: 0.25rem; }
        .action-desc { font-size: 0.75rem; color: #64748b; }
        .urgent-badge { display: inline-block; padding: 0.25rem 0.5rem; background: #fee2e2; color: #dc2626; border-radius: 4px; font-size: 0.75rem; font-weight: 600; margin-top: 0.5rem; }

        .alert-section { background: #fffbeb; border: 1px solid #fef3c7; border-left: 4px solid #f59e0b; padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem; }
        .alert-header { display: flex; align-items: center; justify-content: space-between; }
        .alert-title { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; color: #92400e; font-size: 0.9375rem; }
        .alert-title i { color: #f59e0b; }
        .view-all-link { color: #64748b; text-decoration: none; font-size: 0.875rem; display: flex; align-items: center; gap: 0.25rem; }
        .view-all-link:hover { color: #0f172a; }
        
        .project-item { display: flex; align-items: center; gap: 1rem; padding: 1rem; background: white; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 1rem; transition: all 0.2s; }
        .project-item:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.08); transform: translateX(4px); }
        .project-icon { width: 40px; height: 40px; background: #fef3c7; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #f59e0b; font-size: 1.25rem; flex-shrink: 0; }
        .project-info { flex: 1; }
        .project-name { font-weight: 600; font-size: 0.875rem; color: #0f172a; }
        .project-desc { font-size: 0.75rem; color: #64748b; margin-top: 0.125rem; }
        .project-meta { font-size: 0.75rem; color: #64748b; margin-right: 1rem; }
        .assign-btn { background: #0284c7; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-size: 0.875rem; font-weight: 500; cursor: pointer; transition: all 0.2s; }
        .assign-btn:hover { background: #0369a1; box-shadow: 0 4px 8px rgba(2,132,199,0.3); }
        
        .banner { background: linear-gradient(135deg, #0891b2 0%, #06b6d4 100%); padding: 1.5rem; border-radius: 12px; color: white; transition: all 0.2s; }
        .banner:hover { box-shadow: 0 8px 16px rgba(8,145,178,0.3); transform: translateY(-2px); }
        .banner-icon { width: 56px; height: 56px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin-bottom: 1rem; }
        .banner-title { font-size: 1.25rem; font-weight: 600; margin-bottom: 0.5rem; }
        .banner-text { font-size: 0.875rem; opacity: 0.95; line-height: 1.5; margin-bottom: 1.25rem; }
        .banner-btn { background: white; color: #0891b2; border: none; padding: 0.75rem 1.5rem; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 0.5rem; transition: all 0.2s; }
        .banner-btn:hover { background: #f0f9ff; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        
        .sidebar-section { background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem; transition: all 0.2s; }
        .sidebar-section:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.06); }
        .sidebar-title { font-size: 1rem; font-weight: 600; color: #0f172a; margin-bottom: 1.25rem; }
        
        .progress-item { margin-bottom: 1rem; }
        .progress-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
        .progress-label { font-size: 0.875rem; color: #64748b; }
        .progress-value { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
        .progress-bar-wrapper { height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
        .progress-bar { height: 100%; background: linear-gradient(90deg, #0891b2 0%, #10b981 100%); border-radius: 4px; transition: width 0.3s; }
        
        .prof-list { margin-bottom: 1.5rem; }
        .prof-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #f1f5f9; }
        .prof-item:last-child { border-bottom: none; }
        .prof-name { font-size: 0.875rem; color: #0f172a; }
        .prof-status { display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; }
        .prof-creneaux { color: #64748b; }
        .status-icon { font-size: 1rem; }
        .status-icon.ok { color: #10b981; }
        .status-icon.warning { color: #f59e0b; }
        .status-icon.error { color: #ef4444; }
        
        .send-rappels-btn { width: 100%; padding: 0.75rem; background: white; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.875rem; font-weight: 500; color: #0f172a; cursor: pointer; transition: all 0.2s; }
        .send-rappels-btn:hover { background: #f8fafc; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        
        .overview-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .overview-label { font-size: 0.875rem; color: #64748b; }
        .overview-value { font-size: 0.875rem; font-weight: 600; color: #0f172a; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="sidebar">
            <a href="/Soutenances_PFE/dashboards/coordinateur.php" class="brand">
                <div class="brand-icon">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div class="brand-name">PFE Manager<br><small style="font-size: 0.75rem; font-weight: 400; color: #64748b;">EIDIA</small></div>
            </a>
            
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="/Soutenances_PFE/dashboards/coordinateur.php" class="nav-link active">
                        <i class="bi bi-house-door"></i>
                        <span>Tableau de bord</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/projets/liste.php" class="nav-link">
                        <i class="bi bi-folder"></i>
                        <span>Tous les projets</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/projets/affectation.php" class="nav-link">
                        <i class="bi bi-person-plus"></i>
                        <span>Affecter encadrants</span>
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/Soutenances_PFE/planning/periode.php" class="nav-link">
                        <i class="bi bi-calendar-range"></i>
                        <span>Périodes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/planning/planifier.php" class="nav-link">
                        <i class="bi bi-calendar-check"></i>
                        <span>Planifier</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/planning/voir_planning.php" class="nav-link">
                        <i class="bi bi-calendar"></i>
                        <span>Planning</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/jurys/constituer.php" class="nav-link">
                        <i class="bi bi-people"></i>
                        <span>Constituer jurys</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="/Soutenances_PFE/documents/convocations.php" class="nav-link">
                        <i class="bi bi-file-pdf"></i>
                        <span>Convocations</span>
                    </a>
                </li>
            </ul>
            
            <div class="nav-footer">
                <a href="/Soutenances_PFE/logout.php" class="nav-link">
                    <i class="bi bi-box-arrow-right"></i>
                    <span>Déconnexion</span>
                </a>
            </div>
        </div>
        
        <div class="main-content">
            <div class="page-header">
                <div>
                    <h1 class="page-title">Tableau de bord</h1>
                    <p class="page-subtitle">Filière <?= nettoyer($nom_filiere) ?> • Année 2024-2025</p>
                </div>
                <div class="header-actions">
                    <div class="notification-btn">
                        <i class="bi bi-bell"></i>
                        <span class="notification-badge">3</span>
                    </div>
                    <div class="user-menu">
                        <div class="user-avatar"><?= strtoupper(substr($user['prenom'], 0, 1) . substr($user['nom'], 0, 1)) ?></div>
                        <div class="user-info">
                            <div class="user-name"><?= nettoyer($user['prenom'] . ' ' . $user['nom']) ?></div>
                            <div class="user-role">Coordinateur</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Total Projets</div>
                        <div class="stat-icon yellow"><i class="bi bi-folder"></i></div>
                    </div>
                    <div class="stat-value"><?= $stats['total_projets'] ?></div>
                    <div class="stat-sublabel">Projets enregistrés</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Sans Encadrant</div>
                        <div class="stat-icon blue"><i class="bi bi-person-plus"></i></div>
                    </div>
                    <div class="stat-value"><?= $stats['sans_encadrant'] ?></div>
                    <div class="stat-sublabel">À affecter d'urgence</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Jurys à compléter</div>
                        <div class="stat-icon teal"><i class="bi bi-people"></i></div>
                    </div>
                    <div class="stat-value"><?= $jurys_a_completer ?></div>
                    <div class="stat-sublabel">Constitution en cours</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-label">Convocations</div>
                        <div class="stat-icon cyan"><i class="bi bi-send"></i></div>
                    </div>
                    <div class="stat-value"><?= $convocations_a_envoyer ?></div>
                    <div class="stat-sublabel">Prêtes à envoyer</div>
                </div>
            </div>
            
            <div class="content-grid">
                <div>
                    <div class="section-card">
                        <h3 class="section-title">Actions prioritaires</h3>
                        
                        <div class="action-grid">
                            <div class="action-item">
                                <div class="action-icon yellow"><i class="bi bi-person-plus"></i></div>
                                <div class="action-title">Affecter les encadrants</div>
                                <div class="action-desc"><?= $stats['sans_encadrant'] ?> projets en attente</div>
                                <span class="urgent-badge">Urgent</span>
                            </div>
                            
                            <div class="action-item">
                                <div class="action-icon blue"><i class="bi bi-calendar-check"></i></div>
                                <div class="action-title">Planifier les soutenances</div>
                                <div class="action-desc"><?= $projets_a_planifier ?> projets prêts</div>
                            </div>
                            
                            <div class="action-item">
                                <div class="action-icon teal"><i class="bi bi-people"></i></div>
                                <div class="action-title">Constituer les jurys</div>
                                <div class="action-desc"><?= $jurys_a_completer ?> à compléter</div>
                            </div>
                            
                            <div class="action-item">
                                <div class="action-icon cyan"><i class="bi bi-send"></i></div>
                                <div class="action-title">Envoyer les convocations</div>
                                <div class="action-desc"><?= $convocations_a_envoyer ?> prêtes à envoyer</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert-section">
                        <div class="alert-header">
                            <div class="alert-title">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                Projets en attente d'affectation
                            </div>
                            <a href="/Soutenances_PFE/projets/affectation.php" class="view-all-link">Voir tout <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                    
                    <div class="section-card">
                        <?php if (count($projets_sans_encadrant) > 0): ?>
                            <?php foreach ($projets_sans_encadrant as $projet): ?>
                            <div class="project-item">
                                <div class="project-icon"><i class="bi bi-file-earmark-text"></i></div>
                                <div class="project-info">
                                    <div class="project-name"><?= nettoyer($projet['etudiant_nom']) ?></div>
                                    <div class="project-desc"><?= nettoyer(substr($projet['titre'], 0, 50)) ?>...</div>
                                </div>
                                <span class="project-meta">Il y a <?= (int)$projet['jours_attente'] ?> jours</span>
                                <a href="/Soutenances_PFE/projets/affectation.php?projet_id=<?= (int)$projet['id'] ?>" class="assign-btn">Affecter</a>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: #64748b;">
                                <i class="bi bi-check-circle" style="font-size: 3rem; color: #10b981; margin-bottom: 1rem;"></i>
                                <p style="margin: 0; font-weight: 500;">Tous les projets ont un encadrant !</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="banner">
                        <div class="banner-icon"><i class="bi bi-lightning-charge-fill"></i></div>
                        <h3 class="banner-title">Planification automatique</h3>
                        <p class="banner-text">Générez automatiquement un planning optimal en tenant compte des disponibilités, des contraintes de salles et de l'équilibrage des jurys.</p>
                        <form method="post" action="/Soutenances_PFE/planning/planifier.php" style="display:inline;">
                            <button type="submit" class="banner-btn">
                                Lancer la planification
                                <i class="bi bi-arrow-right"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <div>
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">Saisie des disponibilités</h3>
                        
                        <div class="progress-item">
                            <div class="progress-header">
                                <span class="progress-label">Progression</span>
                                <span class="progress-value"><?= $profs_saisis ?>/<?= $total_profs ?></span>
                            </div>
                            <div class="progress-bar-wrapper">
                                <div class="progress-bar" style="width: <?= $progression_dispos ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="prof-list">
                            <?php foreach ($disponibilites_profs as $prof): 
                                $status_icon = 'ok';
                                if ((int)$prof['nb_creneaux'] < 5) $status_icon = 'error';
                                elseif ((int)$prof['nb_creneaux'] < 10) $status_icon = 'warning';
                            ?>
                            <div class="prof-item">
                                <span class="prof-name">Dr. <?= nettoyer($prof['nom']) ?></span>
                                <div class="prof-status">
                                    <span class="prof-creneaux"><?= (int)$prof['nb_creneaux'] ?> créneaux</span>
                                    <i class="bi bi-check-circle-fill status-icon <?= $status_icon ?>"></i>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <a href="/Soutenances_PFE/disponibilites/saisir.php" class="send-rappels-btn">Envoyer des rappels</a>
                    </div>
                    
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">Vue d'ensemble</h3>
                        
                        <div class="overview-item">
                            <span class="overview-label">Projets traités</span>
                            <span class="overview-value"><?= $traites ?>/<?= $stats['total_projets'] ?></span>
                        </div>
                        <div class="progress-bar-wrapper" style="margin-bottom: 1rem;">
                            <div class="progress-bar" style="width: <?= $progression_projets ?>%"></div>
                        </div>
                        
                        <div class="overview-item">
                            <span class="overview-label">Soutenances planifiées</span>
                            <span class="overview-value"><?= $stats['planifies'] ?>/<?= $stats['total_projets'] ?></span>
                        </div>
                        <div class="progress-bar-wrapper" style="margin-bottom: 1rem;">
                            <div class="progress-bar" style="width: <?= $progression_soutenances ?>%"></div>
                        </div>
                        
                        <div class="overview-item">
                            <span class="overview-label">Convocations envoyées</span>
                            <span class="overview-value"><?= $convocations_envoyees ?>/<?= $total_convocations ?></span>
                        </div>
                        <div class="progress-bar-wrapper">
                            <div class="progress-bar" style="width: <?= $progression_convocations ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
